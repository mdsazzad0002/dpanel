//! OpenAI chat requests in, model prompts out, and the model's text back
//! into an OpenAI reply: content, reasoning and tool calls.
//!
//! Prompts come from the model's own Jinja chat template (what llama-server
//! does with --jinja), so tools, tool results and thinking switches reach
//! the model in the format it was trained on. Templates without tool
//! support get the tools as instructions in the system message instead,
//! and answer in the Hermes `<tool_call>` format that most small models
//! know. Replies are read back in each family's own tool-call format.

use minijinja::{Environment, Error, ErrorKind, Value as JValue, value::Kwargs};
use serde::Deserialize;
use serde_json::{Map, Value, json};
use std::{
    collections::HashMap,
    sync::atomic::{AtomicU64, Ordering},
};

#[derive(Deserialize, Clone)]
pub struct ChatMessage {
    pub role: String,
    #[serde(default)]
    pub content: Value,
    #[serde(default)]
    pub tool_calls: Option<Vec<Value>>,
    #[serde(default)]
    pub tool_call_id: Option<String>,
    #[serde(default)]
    pub name: Option<String>,
}

/// What a template needs besides the messages.
pub struct TemplateInput<'a> {
    pub source: &'a str,
    pub bos: &'a str,
    pub eos: &'a str,
    /// OpenAI tool objects; empty when the request has none (or tool_choice "none").
    pub tools: &'a [Value],
    pub thinking: bool,
    /// The request's chat_template_kwargs, passed to the template as is.
    pub kwargs: &'a Map<String, Value>,
}

/// Whether the template formats tools itself.
pub fn native_tools(source: &str) -> bool {
    source.contains("tools")
}

/// OpenAI content is a string or a list of parts; only text parts are
/// usable here, images need a vision model this runner doesn't serve.
pub fn message_text(content: &Value) -> Result<String, &'static str> {
    match content {
        Value::Null => Ok(String::new()),
        Value::String(text) => Ok(text.clone()),
        Value::Array(parts) => {
            let mut text = String::new();
            for part in parts {
                match part.get("type").and_then(Value::as_str) {
                    Some("text") => {
                        if !text.is_empty() {
                            text.push('\n');
                        }
                        text.push_str(part.get("text").and_then(Value::as_str).unwrap_or(""));
                    }
                    Some("image_url") => return Err("local models can't read images"),
                    _ => {}
                }
            }
            Ok(text)
        }
        _ => Err("message content must be a string or a list of parts"),
    }
}

/// The tool's `function` object (name, description, parameters).
fn function_of(tool: &Value) -> &Value {
    tool.get("function").unwrap_or(tool)
}

pub fn tool_names(tools: &[Value]) -> Vec<String> {
    tools
        .iter()
        .filter_map(|tool| function_of(tool).get("name").and_then(Value::as_str))
        .map(String::from)
        .collect()
}

/// Messages shaped for a template that formats tools itself: one leading
/// system message, arguments as objects, and 9-character call ids (Mistral's
/// template refuses any other length). Templates that list tools but never
/// show earlier calls (Granite, Phi-4-mini) get them written into the
/// assistant's text, in the format the model itself answers with.
pub fn native_messages(messages: &[ChatMessage], source: &str) -> Result<Vec<Value>, String> {
    let shows_calls = source.contains("tool_calls");
    let mut ids = HashMap::new();
    let mut short_id = |id: &str| -> String {
        let next = ids.len();
        ids.entry(id.to_string()).or_insert_with(|| format!("{:0>9}", base36(next as u64 + 1))).clone()
    };
    let mut out = Vec::with_capacity(messages.len());
    for message in merge_leading_system(messages)? {
        let mut value = json!({ "role": message.role, "content": message.text });
        if let Some(calls) = &message.tool_calls {
            let calls: Vec<Value> = calls
                .iter()
                .map(|call| {
                    let function = call.get("function").cloned().unwrap_or_else(|| call.clone());
                    let name = function.get("name").cloned().unwrap_or(Value::Null);
                    let arguments = arguments_object(function.get("arguments").or_else(|| function.get("parameters")));
                    let id = call.get("id").and_then(Value::as_str).unwrap_or("");
                    json!({
                        "id": short_id(id),
                        "type": "function",
                        "function": { "name": name, "arguments": arguments },
                        // Some templates read these at the top level.
                        "name": name,
                        "arguments": arguments,
                    })
                })
                .collect();
            if !shows_calls {
                let list: Vec<Value> = calls.iter().map(|c| json!({ "name": c["name"], "arguments": c["arguments"] })).collect();
                let list = Value::Array(list).to_string();
                let written = if source.contains("<|/tool|>") {
                    format!("<|tool_call|>{list}<|/tool_call|>")
                } else if source.contains("<|tool_call|>") {
                    format!("<|tool_call|>{list}")
                } else {
                    calls
                        .iter()
                        .map(|c| format!("<tool_call>\n{}\n</tool_call>", json!({ "name": c["name"], "arguments": c["arguments"] })))
                        .collect::<Vec<_>>()
                        .join("\n")
                };
                let text = value["content"].as_str().unwrap_or("").trim().to_string();
                value["content"] = Value::String(if text.is_empty() { written } else { format!("{text}\n{written}") });
            }
            value["tool_calls"] = Value::Array(calls);
        }
        if message.role == "tool" {
            if let Some(id) = &message.tool_call_id {
                value["tool_call_id"] = Value::String(short_id(id));
            }
        }
        if let Some(name) = &message.name {
            value["name"] = Value::String(name.clone());
        }
        out.push(value);
    }
    Ok(out)
}

/// Messages for a template without tool support: tools described in the
/// system message, earlier calls and their results written out as text, and
/// roles strictly alternating (Gemma's and Mistral's templates insist).
pub fn generic_messages(messages: &[ChatMessage], tools: &[Value]) -> Result<Vec<(String, String)>, String> {
    let mut merged = merge_leading_system(messages)?;
    if !tools.is_empty() {
        let prompt = generic_tool_prompt(tools);
        match merged.first_mut() {
            Some(first) if first.role == "system" => {
                first.text = format!("{}\n\n{prompt}", first.text.trim_end());
            }
            _ => merged.insert(0, Plain { role: "system".into(), text: prompt, tool_calls: None, tool_call_id: None, name: None }),
        }
    }
    let mut out: Vec<(String, String)> = Vec::with_capacity(merged.len());
    for message in merged {
        let (role, text) = match message.role.as_str() {
            "tool" | "ipython" | "function" => {
                ("user".to_string(), format!("<tool_response>\n{}\n</tool_response>", message.text.trim()))
            }
            "assistant" => {
                let mut text = message.text.trim().to_string();
                for call in message.tool_calls.iter().flatten() {
                    let function = call.get("function").unwrap_or(call);
                    let block = json!({
                        "name": function.get("name").cloned().unwrap_or(Value::Null),
                        "arguments": arguments_object(function.get("arguments")),
                    });
                    if !text.is_empty() {
                        text.push('\n');
                    }
                    text.push_str(&format!("<tool_call>\n{block}\n</tool_call>"));
                }
                ("assistant".to_string(), text)
            }
            "developer" => ("system".to_string(), message.text),
            other => (other.to_string(), message.text),
        };
        match out.last_mut() {
            Some((last_role, last_text)) if *last_role == role => {
                last_text.push_str("\n\n");
                last_text.push_str(&text);
            }
            _ => out.push((role, text)),
        }
    }
    Ok(out)
}

fn generic_tool_prompt(tools: &[Value]) -> String {
    let list = tools
        .iter()
        .map(|tool| {
            let function = function_of(tool);
            json!({
                "name": function.get("name").cloned().unwrap_or(Value::Null),
                "description": function.get("description").cloned().unwrap_or(Value::Null),
                "parameters": function.get("parameters").cloned().unwrap_or_else(|| json!({})),
            })
            .to_string()
        })
        .collect::<Vec<_>>()
        .join("\n");
    format!(
        "# Tools\n\nYou can call these tools. Each is a JSON object with its name, what it does and its JSON Schema parameters:\n<tools>\n{list}\n</tools>\n\n\
         To call a tool, reply with only this block (one block per call) and then stop:\n<tool_call>\n{{\"name\": \"<tool name>\", \"arguments\": {{\"<parameter>\": \"<value>\"}}}}\n</tool_call>\n\n\
         Results come back inside <tool_response></tool_response>. Use them to answer. Never make up a result. If no tool is needed, answer normally."
    )
}

struct Plain {
    role: String,
    text: String,
    tool_calls: Option<Vec<Value>>,
    tool_call_id: Option<String>,
    name: Option<String>,
}

/// The panel sends several system messages (persona, search results, tool
/// rules); most templates accept only one, first.
fn merge_leading_system(messages: &[ChatMessage]) -> Result<Vec<Plain>, String> {
    let mut out: Vec<Plain> = Vec::with_capacity(messages.len());
    let mut leading = true;
    for message in messages {
        let text = message_text(&message.content).map_err(String::from)?;
        let system = matches!(message.role.as_str(), "system" | "developer");
        if leading && system {
            if let Some(first) = out.first_mut() {
                first.text.push_str("\n\n");
                first.text.push_str(&text);
                continue;
            }
            out.push(Plain { role: "system".into(), text, tool_calls: None, tool_call_id: None, name: None });
            continue;
        }
        leading = false;
        out.push(Plain {
            role: message.role.clone(),
            text,
            tool_calls: message.tool_calls.clone().filter(|calls| !calls.is_empty()),
            tool_call_id: message.tool_call_id.clone(),
            name: message.name.clone(),
        });
    }
    Ok(out)
}

/// Arguments as a JSON object: OpenAI sends them as a JSON string, templates
/// expect a mapping.
fn arguments_object(arguments: Option<&Value>) -> Value {
    match arguments {
        Some(Value::String(text)) => match serde_json::from_str::<Value>(text) {
            Ok(value @ Value::Object(_)) => value,
            _ => json!({}),
        },
        Some(value @ Value::Object(_)) => value.clone(),
        _ => json!({}),
    }
}

/// Renders the template the way transformers does (trim_blocks,
/// lstrip_blocks, loop controls, Python string methods).
pub fn render_jinja(input: &TemplateInput, messages: &[Value], with_tools: bool) -> Result<String, String> {
    let mut env = Environment::new();
    env.set_trim_blocks(true);
    env.set_lstrip_blocks(true);
    env.set_unknown_method_callback(minijinja_contrib::pycompat::unknown_method_callback);
    env.add_function("raise_exception", |message: String| -> Result<JValue, Error> {
        Err(Error::new(ErrorKind::InvalidOperation, message))
    });
    env.add_function("strftime_now", |format: String| strftime(&format, crate::registry::unix_now()));
    env.add_filter("tojson", tojson);
    let source = strip_generation_tags(input.source);
    let template = env.template_from_str(&source).map_err(|error| format!("chat template: {error}"))?;

    let mut messages = messages.to_vec();
    let tools: Vec<Value> = if with_tools { input.tools.to_vec() } else { Vec::new() };
    // Phi-4-mini reads the tools from the system message, as a JSON string.
    if with_tools && !tools.is_empty() && input.source.contains("message['tools']") {
        let functions: Vec<&Value> = tools.iter().map(function_of).collect();
        let list = serde_json::to_string(&functions).unwrap_or_default();
        match messages.first_mut() {
            Some(first) if first["role"] == "system" => first["tools"] = Value::String(list),
            _ => messages.insert(0, json!({ "role": "system", "content": "", "tools": list })),
        }
    }

    let mut context = Map::new();
    context.insert("messages".into(), Value::Array(messages));
    context.insert("tools".into(), if tools.is_empty() { Value::Null } else { Value::Array(tools) });
    context.insert("add_generation_prompt".into(), Value::Bool(true));
    context.insert("bos_token".into(), Value::String(input.bos.to_string()));
    context.insert("eos_token".into(), Value::String(input.eos.to_string()));
    context.insert("enable_thinking".into(), Value::Bool(input.thinking));
    context.insert("date_string".into(), Value::String(strftime("%d %b %Y", crate::registry::unix_now())));
    for (key, value) in input.kwargs {
        context.insert(key.clone(), value.clone());
    }
    template
        .render(JValue::from_serialize(&context))
        .map_err(|error| format!("chat template: {error}"))
}

/// `{% generation %}…{% endgeneration %}` marks training targets in
/// transformers (SmolLM3); it isn't Jinja and changes nothing when rendering.
fn strip_generation_tags(source: &str) -> String {
    let mut out = String::with_capacity(source.len());
    let mut rest = source;
    while let Some(start) = rest.find("{%") {
        let Some(len) = rest[start..].find("%}") else { break };
        let tag = &rest[start..start + len + 2];
        let inner = tag[2..tag.len() - 2].trim_matches(|c: char| c == '-' || c == '+' || c.is_whitespace());
        out.push_str(&rest[..start]);
        if inner != "generation" && inner != "endgeneration" {
            out.push_str(tag);
        }
        rest = &rest[start + len + 2..];
    }
    out.push_str(rest);
    out
}

/// Python's json.dumps output (", " and ": " separators, no HTML escaping),
/// which is what the models saw in training; minijinja's own tojson differs.
fn tojson(value: JValue, kwargs: Kwargs) -> Result<String, Error> {
    let indent: Option<usize> = kwargs.get("indent")?;
    let _: Option<JValue> = kwargs.get("ensure_ascii")?;
    let _: Option<JValue> = kwargs.get("separators")?;
    let sort_keys: Option<bool> = kwargs.get("sort_keys")?;
    kwargs.assert_all_used()?;
    let mut value = serde_json::to_value(&value).map_err(|error| Error::new(ErrorKind::InvalidOperation, error.to_string()))?;
    if sort_keys == Some(true) {
        value = sorted(value);
    }
    let mut out = Vec::new();
    let result = match indent {
        Some(width) => {
            let pad = " ".repeat(width);
            let mut ser = serde_json::Serializer::with_formatter(&mut out, serde_json::ser::PrettyFormatter::with_indent(pad.as_bytes()));
            serde::Serialize::serialize(&value, &mut ser)
        }
        None => {
            let mut ser = serde_json::Serializer::with_formatter(&mut out, PythonFormatter);
            serde::Serialize::serialize(&value, &mut ser)
        }
    };
    result.map_err(|error| Error::new(ErrorKind::InvalidOperation, error.to_string()))?;
    Ok(String::from_utf8(out).unwrap_or_default())
}

fn sorted(value: Value) -> Value {
    match value {
        Value::Object(map) => {
            let mut entries: Vec<(String, Value)> = map.into_iter().collect();
            entries.sort_by(|a, b| a.0.cmp(&b.0));
            Value::Object(entries.into_iter().map(|(k, v)| (k, sorted(v))).collect())
        }
        Value::Array(items) => Value::Array(items.into_iter().map(sorted).collect()),
        other => other,
    }
}

struct PythonFormatter;

impl serde_json::ser::Formatter for PythonFormatter {
    fn begin_array_value<W: ?Sized + std::io::Write>(&mut self, writer: &mut W, first: bool) -> std::io::Result<()> {
        if first { Ok(()) } else { writer.write_all(b", ") }
    }
    fn begin_object_key<W: ?Sized + std::io::Write>(&mut self, writer: &mut W, first: bool) -> std::io::Result<()> {
        if first { Ok(()) } else { writer.write_all(b", ") }
    }
    fn begin_object_value<W: ?Sized + std::io::Write>(&mut self, writer: &mut W) -> std::io::Result<()> {
        writer.write_all(b": ")
    }
}

/// The few strftime codes chat templates use (Llama 3's "Today Date").
fn strftime(format: &str, unix: u64) -> String {
    const MONTHS: [&str; 12] =
        ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
    let days = (unix / 86_400) as i64;
    let secs = unix % 86_400;
    // Howard Hinnant's civil_from_days.
    let z = days + 719_468;
    let era = z.div_euclid(146_097);
    let doe = z.rem_euclid(146_097);
    let yoe = (doe - doe / 1460 + doe / 36_524 - doe / 146_096) / 365;
    let doy = doe - (365 * yoe + yoe / 4 - yoe / 100);
    let mp = (5 * doy + 2) / 153;
    let day = doy - (153 * mp + 2) / 5 + 1;
    let month = if mp < 10 { mp + 3 } else { mp - 9 };
    let year = yoe + era * 400 + i64::from(month <= 2);
    let name = MONTHS[(month - 1) as usize];

    let mut out = String::new();
    let mut chars = format.chars();
    while let Some(c) = chars.next() {
        if c != '%' {
            out.push(c);
            continue;
        }
        match chars.next() {
            Some('d') => out.push_str(&format!("{day:02}")),
            Some('m') => out.push_str(&format!("{month:02}")),
            Some('Y') => out.push_str(&year.to_string()),
            Some('y') => out.push_str(&format!("{:02}", year % 100)),
            Some('b') => out.push_str(&name[..3]),
            Some('B') => out.push_str(name),
            Some('H') => out.push_str(&format!("{:02}", secs / 3600)),
            Some('M') => out.push_str(&format!("{:02}", secs / 60 % 60)),
            Some('S') => out.push_str(&format!("{:02}", secs % 60)),
            Some('%') => out.push('%'),
            Some(other) => {
                out.push('%');
                out.push(other);
            }
            None => out.push('%'),
        }
    }
    out
}

fn base36(mut n: u64) -> String {
    const DIGITS: &[u8] = b"0123456789abcdefghijklmnopqrstuvwxyz";
    let mut out = Vec::new();
    loop {
        out.push(DIGITS[(n % 36) as usize]);
        n /= 36;
        if n == 0 {
            break;
        }
    }
    out.reverse();
    String::from_utf8(out).unwrap_or_default()
}

/// A fresh id for a tool call this runner returns.
fn call_id() -> String {
    static COUNTER: AtomicU64 = AtomicU64::new(0);
    use std::hash::{BuildHasher, Hasher};
    let mut hasher = std::collections::hash_map::RandomState::new().build_hasher();
    hasher.write_u64(COUNTER.fetch_add(1, Ordering::Relaxed));
    hasher.write_u64(crate::registry::unix_now());
    format!("call_{:0>9}", base36(hasher.finish() % 36u64.pow(9)))
}

// ---------------------------------------------------------------------------
// Reading the reply

pub enum Delta {
    Content(String),
    Reasoning(String),
}

const THINK_OPEN: &str = "<think>";
const THINK_CLOSE: &str = "</think>";

/// Where a tool call starts, in the formats of the model families in the
/// catalog: Hermes/Qwen/SmolLM3, Granite and Phi-4, Mistral, Llama 3.1.
const MARKERS: &[&str] = &["<tool_call>", "<|tool_call|>", "[TOOL_CALLS]", "<|python_tag|>", "functools["];

/// Splits the streamed text into reasoning (`<think>…</think>`), answer and
/// tool calls. Answer text is passed on as it comes; from a tool-call
/// marker on, the rest is held and read as calls at the end.
pub struct OutputParser {
    tools: Vec<String>,
    thinking: bool,
    started: bool,
    pending: String,
    capture: Option<String>,
}

impl OutputParser {
    /// `think_open`: the prompt already opened a `<think>` block.
    pub fn new(tools: Vec<String>, think_open: bool) -> Self {
        Self { tools, thinking: think_open, started: false, pending: String::new(), capture: None }
    }

    pub fn push(&mut self, text: &str) -> Vec<Delta> {
        self.pending.push_str(text);
        let mut out = Vec::new();
        loop {
            if self.thinking {
                if let Some(at) = self.pending.find(THINK_CLOSE) {
                    let reasoning = self.pending[..at].to_string();
                    self.pending = self.pending[at + THINK_CLOSE.len()..].to_string();
                    self.thinking = false;
                    self.started = false;
                    if !reasoning.is_empty() {
                        out.push(Delta::Reasoning(reasoning));
                    }
                    continue;
                }
                let hold = partial_suffix(&self.pending, &[THINK_CLOSE]);
                let split = self.pending.len() - hold;
                if split > 0 {
                    out.push(Delta::Reasoning(self.pending[..split].to_string()));
                    self.pending.drain(..split);
                }
                return out;
            }
            if let Some(capture) = self.capture.as_mut() {
                capture.push_str(&self.pending);
                self.pending.clear();
                return out;
            }
            if !self.started {
                let trimmed = self.pending.trim_start();
                if trimmed.is_empty() {
                    return out;
                }
                if let Some(rest) = trimmed.strip_prefix(THINK_OPEN) {
                    self.pending = rest.to_string();
                    self.thinking = true;
                    continue;
                }
                let tool_start = |t: &str| t.starts_with('{') || t.starts_with('[') || t.starts_with("```");
                if THINK_OPEN.starts_with(trimmed) || (!self.tools.is_empty() && "```".starts_with(trimmed)) {
                    return out; // could still become one of those
                }
                self.started = true;
                self.pending = trimmed.to_string();
                // Llama 3.x writes bare JSON; others sometimes fence it.
                if !self.tools.is_empty() && tool_start(&self.pending) {
                    self.capture = Some(std::mem::take(&mut self.pending));
                    return out;
                }
            }
            if self.tools.is_empty() {
                out.push(Delta::Content(std::mem::take(&mut self.pending)));
                return out;
            }
            if let Some(at) = MARKERS.iter().filter_map(|m| self.pending.find(m)).min() {
                if at > 0 {
                    out.push(Delta::Content(self.pending[..at].to_string()));
                }
                self.capture = Some(self.pending[at..].to_string());
                self.pending.clear();
                return out;
            }
            let hold = partial_suffix(&self.pending, MARKERS);
            let split = self.pending.len() - hold;
            if split > 0 {
                out.push(Delta::Content(self.pending[..split].to_string()));
                self.pending.drain(..split);
            }
            return out;
        }
    }

    pub fn finish(&mut self) -> (Vec<Delta>, Vec<Value>) {
        let mut out = Vec::new();
        let pending = std::mem::take(&mut self.pending);
        if self.thinking {
            if !pending.is_empty() {
                out.push(Delta::Reasoning(pending));
            }
            return (out, Vec::new());
        }
        if let Some(mut capture) = self.capture.take() {
            capture.push_str(&pending);
            let (calls, rest) = parse_tool_calls(&capture, &self.tools);
            if calls.is_empty() {
                out.push(Delta::Content(capture));
            } else if !rest.trim().is_empty() {
                out.push(Delta::Content(rest.trim().to_string()));
            }
            return (out, calls);
        }
        if self.started && !pending.is_empty() {
            out.push(Delta::Content(pending));
        }
        (out, Vec::new())
    }
}

/// Bytes at the end of `text` that could be the start of one of `markers`.
fn partial_suffix(text: &str, markers: &[&str]) -> usize {
    markers
        .iter()
        .flat_map(|marker| marker.char_indices().skip(1).map(move |(i, _)| &marker[..i]))
        .filter(|prefix| text.ends_with(prefix))
        .map(str::len)
        .max()
        .unwrap_or(0)
}

/// Tool calls in `text`, and whatever text is left around them.
pub fn parse_tool_calls(text: &str, tools: &[String]) -> (Vec<Value>, String) {
    let mut calls = Vec::new();
    let mut rest = String::new();
    let mut s = text;
    let mut found_marker = false;
    while let Some((at, marker)) = MARKERS.iter().filter_map(|m| s.find(m).map(|at| (at, *m))).min_by_key(|(at, _)| *at) {
        found_marker = true;
        rest.push_str(&s[..at]);
        let after = &s[at + marker.len()..];
        let (body, consumed) = if marker == "functools[" {
            // functools[{...}, {...}]: the bracket belongs to the JSON.
            let body = format!("[{after}");
            match json_values(&body) {
                Some((values, used)) => (values, used.saturating_sub(1)),
                None => (Vec::new(), 0),
            }
        } else {
            json_values(after).unwrap_or((Vec::new(), 0))
        };
        let before = calls.len();
        for value in body {
            collect_calls(&value, tools, &mut calls);
        }
        if calls.len() == before {
            rest.push_str(&s[at..]);
            s = "";
            break;
        }
        s = after[consumed..].trim_start();
        for closing in ["</tool_call>", "<|/tool_call|>"] {
            if let Some(stripped) = s.strip_prefix(closing) {
                s = stripped;
            }
        }
    }
    rest.push_str(s);
    if !found_marker {
        // Bare JSON (Llama 3.x), maybe in a ```json fence.
        let body = rest.trim();
        let body = body
            .strip_prefix("```json")
            .or_else(|| body.strip_prefix("```"))
            .map(|b| b.trim_end().strip_suffix("```").unwrap_or(b))
            .unwrap_or(body);
        if let Some((values, used)) = json_values(body) {
            if body[used..].trim().is_empty() {
                let mut bare = Vec::new();
                for value in &values {
                    collect_calls(value, tools, &mut bare);
                }
                if !bare.is_empty() && bare.len() >= values.len() {
                    return (bare, String::new());
                }
            }
        }
    }
    (calls, rest)
}

/// One or more JSON values at the start of `text`, separated by spaces,
/// commas or semicolons (Llama 3.1 joins several calls with ';').
fn json_values(text: &str) -> Option<(Vec<Value>, usize)> {
    let mut values = Vec::new();
    let mut offset = 0;
    loop {
        let skipped = text[offset..].len() - text[offset..].trim_start_matches(|c: char| c.is_whitespace() || c == ';' || c == ',').len();
        let start = offset + skipped;
        if !text[start..].starts_with(['{', '[']) {
            break;
        }
        let mut stream = serde_json::Deserializer::from_str(&text[start..]).into_iter::<Value>();
        match stream.next() {
            Some(Ok(value)) => {
                offset = start + stream.byte_offset();
                values.push(value);
            }
            _ => break,
        }
    }
    (!values.is_empty()).then_some((values, offset))
}

fn collect_calls(value: &Value, tools: &[String], calls: &mut Vec<Value>) {
    match value {
        Value::Array(items) => items.iter().for_each(|item| collect_calls(item, tools, calls)),
        Value::Object(map) => {
            let function = map.get("function").filter(|f| f.is_object());
            let field = |key: &str| function.and_then(|f| f.get(key)).or_else(|| map.get(key));
            let Some(name) = field("name").and_then(Value::as_str) else { return };
            if !tools.is_empty() && !tools.iter().any(|tool| tool == name) {
                return;
            }
            let arguments = arguments_object(field("arguments").or_else(|| field("parameters")));
            calls.push(json!({
                "id": call_id(),
                "type": "function",
                "function": { "name": name, "arguments": arguments.to_string() },
            }));
        }
        _ => {}
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn tools() -> Vec<String> {
        vec!["list_websites".into(), "get_weather".into()]
    }

    fn run(parser: &mut OutputParser, pieces: &[&str]) -> (String, String, Vec<Value>) {
        let (mut content, mut reasoning) = (String::new(), String::new());
        let mut take = |deltas: Vec<Delta>| {
            for delta in deltas {
                match delta {
                    Delta::Content(text) => content.push_str(&text),
                    Delta::Reasoning(text) => reasoning.push_str(&text),
                }
            }
        };
        for piece in pieces {
            take(parser.push(piece));
        }
        let (deltas, calls) = parser.finish();
        take(deltas);
        (content, reasoning, calls)
    }

    fn name_args(call: &Value) -> (String, Value) {
        let function = &call["function"];
        (
            function["name"].as_str().unwrap().to_string(),
            serde_json::from_str(function["arguments"].as_str().unwrap()).unwrap(),
        )
    }

    #[test]
    fn plain_answer_streams() {
        let mut parser = OutputParser::new(tools(), false);
        let (content, reasoning, calls) = run(&mut parser, &["Hello", " there", "!"]);
        assert_eq!(content, "Hello there!");
        assert!(reasoning.is_empty() && calls.is_empty());
    }

    #[test]
    fn hermes_tool_call() {
        let mut parser = OutputParser::new(tools(), false);
        let (content, _, calls) =
            run(&mut parser, &["Let me check.\n<tool", "_call>\n{\"name\": \"get_weather\", \"arguments\": {\"city\": \"Dhaka\"}}\n</tool_call>"]);
        assert_eq!(content, "Let me check.\n");
        assert_eq!(calls.len(), 1);
        assert_eq!(name_args(&calls[0]), ("get_weather".into(), json!({"city": "Dhaka"})));
    }

    #[test]
    fn llama_bare_json_and_parameters() {
        let mut parser = OutputParser::new(tools(), false);
        let (content, _, calls) = run(&mut parser, &["{\"name\": \"list_websites\", \"parameters\": {}}"]);
        assert!(content.is_empty());
        assert_eq!(name_args(&calls[0]).0, "list_websites");
    }

    #[test]
    fn mistral_and_granite_arrays() {
        for text in [
            "[TOOL_CALLS] [{\"name\": \"get_weather\", \"arguments\": {\"city\": \"A\"}}, {\"name\": \"list_websites\", \"arguments\": {}}]",
            "<|tool_call|>[{\"name\": \"get_weather\", \"arguments\": {\"city\": \"A\"}}, {\"name\": \"list_websites\", \"arguments\": {}}]",
            "functools[{\"name\": \"get_weather\", \"arguments\": {\"city\": \"A\"}}, {\"name\": \"list_websites\", \"arguments\": {}}]",
        ] {
            let (calls, rest) = parse_tool_calls(text, &tools());
            assert_eq!(calls.len(), 2, "{text}");
            assert!(rest.trim().is_empty(), "{text}: {rest}");
        }
    }

    #[test]
    fn unknown_tool_stays_text() {
        let mut parser = OutputParser::new(tools(), false);
        let (content, _, calls) = run(&mut parser, &["{\"name\": \"rm_rf\", \"arguments\": {}}"]);
        assert!(calls.is_empty());
        assert!(content.contains("rm_rf"));
    }

    #[test]
    fn json_answer_without_tools_is_content() {
        let mut parser = OutputParser::new(Vec::new(), false);
        let (content, _, calls) = run(&mut parser, &["{\"a\": 1}"]);
        assert_eq!(content, "{\"a\": 1}");
        assert!(calls.is_empty());
    }

    #[test]
    fn thinking_is_split_out() {
        let mut parser = OutputParser::new(tools(), false);
        let (content, reasoning, _) = run(&mut parser, &["<thi", "nk>\nhmm, ", "let me see</th", "ink>\n\nThe answer is 4."]);
        assert_eq!(reasoning.trim(), "hmm, let me see");
        assert_eq!(content, "The answer is 4.");
    }

    #[test]
    fn prompt_opened_think() {
        let mut parser = OutputParser::new(Vec::new(), true);
        let (content, reasoning, _) = run(&mut parser, &["step 1</think>Done"]);
        assert_eq!(reasoning, "step 1");
        assert_eq!(content, "Done");
    }

    #[test]
    fn generic_messages_alternate() {
        let messages: Vec<ChatMessage> = serde_json::from_value(json!([
            {"role": "system", "content": "persona"},
            {"role": "system", "content": "rules"},
            {"role": "user", "content": "weather?"},
            {"role": "assistant", "content": null, "tool_calls": [{"id": "x", "type": "function", "function": {"name": "get_weather", "arguments": "{\"city\":\"Dhaka\"}"}}]},
            {"role": "tool", "tool_call_id": "x", "content": "31C"},
            {"role": "tool", "tool_call_id": "y", "content": "humid"},
        ]))
        .unwrap();
        let tools = vec![json!({"type": "function", "function": {"name": "get_weather", "parameters": {}}})];
        let out = generic_messages(&messages, &tools).unwrap();
        let roles: Vec<&str> = out.iter().map(|(role, _)| role.as_str()).collect();
        assert_eq!(roles, ["system", "user", "assistant", "user"]);
        assert!(out[0].1.starts_with("persona\n\nrules"));
        assert!(out[0].1.contains("<tools>"));
        assert!(out[2].1.contains("<tool_call>"));
        assert!(out[3].1.contains("31C") && out[3].1.contains("humid"));
    }

    #[test]
    fn jinja_renders_tools_and_python_methods() {
        let source = "{%- if tools %}TOOLS:{% for t in tools %}{{ t | tojson }}{% endfor %}\n{% endif %}\
            {%- for m in messages %}<{{ m.role }}>{{ m.content.strip() }}{% if m.tool_calls %}{% for c in m.tool_calls %}[{{ c.id }}:{{ c.function.arguments | tojson }}]{% endfor %}{% endif %}\n{% endfor %}\
            {%- if add_generation_prompt %}<assistant>{% if not enable_thinking %}<think></think>{% endif %}{% endif %}";
        let messages: Vec<ChatMessage> = serde_json::from_value(json!([
            {"role": "system", "content": " a "},
            {"role": "system", "content": "b"},
            {"role": "assistant", "content": "", "tool_calls": [{"id": "long-id", "function": {"name": "get_weather", "arguments": "{\"city\":\"Dhaka\"}"}}]},
        ]))
        .unwrap();
        let tools = vec![json!({"type": "function", "function": {"name": "get_weather"}})];
        let kwargs = Map::new();
        let input = TemplateInput { source, bos: "", eos: "", tools: &tools, thinking: false, kwargs: &kwargs };
        let rendered = render_jinja(&input, &native_messages(&messages, source).unwrap(), true).unwrap();
        assert!(rendered.starts_with("TOOLS:{\"type\": \"function\", \"function\": {\"name\": \"get_weather\"}}"), "{rendered}");
        assert!(rendered.contains("<system>a \n\nb"), "{rendered}");
        assert!(rendered.contains("[000000001:{\"city\": \"Dhaka\"}]"), "{rendered}");
        assert!(rendered.ends_with("<assistant><think></think>"), "{rendered}");
    }

    #[test]
    fn raise_exception_fails_render() {
        let kwargs = Map::new();
        let input = TemplateInput { source: "{{ raise_exception('no') }}", bos: "", eos: "", tools: &[], thinking: false, kwargs: &kwargs };
        assert!(render_jinja(&input, &[], false).is_err());
    }

    #[test]
    fn strftime_date() {
        // 2026-11-10 12:34:56 UTC
        assert_eq!(strftime("%d %b %Y %H:%M:%S", 1_794_314_096), "10 Nov 2026 12:34:56");
    }
}

