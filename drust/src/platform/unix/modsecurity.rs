//! libmodsecurity 3 through its C API, opened with `dlopen` so drust neither
//! links nor needs it. Used only inside a `drust waf-worker` process.

use std::{
    ffi::{CStr, CString, c_char, c_int, c_void},
    path::Path,
};

use crate::platform::{
    PlatformError,
    waf::{Verdict, WafEngine, WafRequest},
};

#[cfg(target_os = "macos")]
const LIBRARY_NAMES: [&str; 2] = ["libmodsecurity.3.dylib", "libmodsecurity.dylib"];
#[cfg(not(target_os = "macos"))]
const LIBRARY_NAMES: [&str; 2] = ["libmodsecurity.so.3", "libmodsecurity.so"];

/// Kept small: the panel shows them, and a match on a huge argument repeats it.
const MAX_MESSAGE: usize = 2000;
const MAX_MESSAGES: usize = 50;

#[repr(C)]
struct Intervention {
    status: c_int,
    pause: c_int,
    url: *mut c_char,
    log: *mut c_char,
    disruptive: c_int,
}

type LogCallback = extern "C" fn(*mut c_void, *const c_void);

/// The functions of the C API this uses.
struct Api {
    init: unsafe extern "C" fn() -> *mut c_void,
    set_connector_info: unsafe extern "C" fn(*mut c_void, *const c_char),
    set_log_cb: unsafe extern "C" fn(*mut c_void, LogCallback),
    create_rules_set: unsafe extern "C" fn() -> *mut c_void,
    rules_add_file: unsafe extern "C" fn(*mut c_void, *const c_char, *mut *const c_char) -> c_int,
    new_transaction: unsafe extern "C" fn(*mut c_void, *mut c_void, *mut c_void) -> *mut c_void,
    process_connection: unsafe extern "C" fn(*mut c_void, *const c_char, c_int, *const c_char, c_int) -> c_int,
    process_uri: unsafe extern "C" fn(*mut c_void, *const c_char, *const c_char, *const c_char) -> c_int,
    add_n_request_header: unsafe extern "C" fn(*mut c_void, *const u8, usize, *const u8, usize) -> c_int,
    process_request_headers: unsafe extern "C" fn(*mut c_void) -> c_int,
    append_request_body: unsafe extern "C" fn(*mut c_void, *const u8, usize) -> c_int,
    process_request_body: unsafe extern "C" fn(*mut c_void) -> c_int,
    intervention: unsafe extern "C" fn(*mut c_void, *mut Intervention) -> c_int,
    process_logging: unsafe extern "C" fn(*mut c_void) -> c_int,
    transaction_cleanup: unsafe extern "C" fn(*mut c_void),
}

fn open_library() -> Option<*mut c_void> {
    LIBRARY_NAMES.iter().find_map(|name| {
        let name = CString::new(*name).ok()?;
        // SAFETY: a plain dlopen of a library name; the handle is never closed.
        let handle = unsafe { libc::dlopen(name.as_ptr(), libc::RTLD_NOW | libc::RTLD_LOCAL) };
        (!handle.is_null()).then_some(handle)
    })
}

pub fn library_present() -> bool {
    open_library().is_some()
}

fn failed(detail: impl Into<String>) -> PlatformError {
    PlatformError::Failed { action: "load the WAF".into(), detail: detail.into() }
}

impl Api {
    fn load() -> Result<Self, PlatformError> {
        let handle = open_library().ok_or_else(|| failed("libmodsecurity 3 is not installed"))?;
        macro_rules! symbol {
            ($name:literal) => {{
                let name = CString::new($name).unwrap();
                // SAFETY: the symbol is looked up in the library just opened.
                let pointer = unsafe { libc::dlsym(handle, name.as_ptr()) };
                if pointer.is_null() {
                    return Err(failed(concat!("libmodsecurity has no ", $name)));
                }
                // SAFETY: the type matches the C declaration in modsecurity.h.
                unsafe { std::mem::transmute(pointer) }
            }};
        }
        Ok(Api {
            init: symbol!("msc_init"),
            set_connector_info: symbol!("msc_set_connector_info"),
            set_log_cb: symbol!("msc_set_log_cb"),
            create_rules_set: symbol!("msc_create_rules_set"),
            rules_add_file: symbol!("msc_rules_add_file"),
            new_transaction: symbol!("msc_new_transaction"),
            process_connection: symbol!("msc_process_connection"),
            process_uri: symbol!("msc_process_uri"),
            add_n_request_header: symbol!("msc_add_n_request_header"),
            process_request_headers: symbol!("msc_process_request_headers"),
            append_request_body: symbol!("msc_append_request_body"),
            process_request_body: symbol!("msc_process_request_body"),
            intervention: symbol!("msc_intervention"),
            process_logging: symbol!("msc_process_logging"),
            transaction_cleanup: symbol!("msc_transaction_cleanup"),
        })
    }
}

/// Each rule match arrives here; the transaction's own message list is `data`.
extern "C" fn collect(data: *mut c_void, message: *const c_void) {
    if data.is_null() || message.is_null() {
        return;
    }
    // SAFETY: `data` is the `Vec<String>` this transaction was given, alive
    // for its whole run; the message is a C string from libmodsecurity.
    let (messages, text) = unsafe { (&mut *(data as *mut Vec<String>), CStr::from_ptr(message as *const c_char)) };
    if messages.len() < MAX_MESSAGES {
        let mut text = text.to_string_lossy().into_owned();
        if text.len() > MAX_MESSAGE {
            let mut cut = MAX_MESSAGE;
            while !text.is_char_boundary(cut) {
                cut -= 1;
            }
            text.truncate(cut);
            text.push('…');
        }
        messages.push(text);
    }
}

fn c_string(value: &str) -> CString {
    CString::new(value.replace('\0', "")).unwrap_or_default()
}

pub struct ModSecurity {
    api: Api,
    engine: *mut c_void,
    rules: *mut c_void,
}

// SAFETY: one worker thread owns the engine; it is never shared.
unsafe impl Send for ModSecurity {}

impl ModSecurity {
    pub fn load(rules_file: &Path) -> Result<Self, PlatformError> {
        let api = Api::load()?;
        let file = c_string(&rules_file.to_string_lossy());
        // SAFETY: calls follow the C API's documented order; pointers come from it.
        unsafe {
            let engine = (api.init)();
            (api.set_connector_info)(engine, c"drust".as_ptr());
            (api.set_log_cb)(engine, collect);
            let rules = (api.create_rules_set)();
            let mut error: *const c_char = std::ptr::null();
            if (api.rules_add_file)(rules, file.as_ptr(), &mut error) < 0 {
                let detail = if error.is_null() { "the rules did not load".into() } else { CStr::from_ptr(error).to_string_lossy().into_owned() };
                return Err(failed(detail));
            }
            Ok(Self { api, engine, rules })
        }
    }
}

impl WafEngine for ModSecurity {
    fn inspect(&mut self, request: &WafRequest) -> Verdict {
        let api = &self.api;
        let mut messages: Vec<String> = Vec::new();
        let client = c_string(&request.client_ip);
        let (uri, method, version) = (c_string(&request.uri), c_string(&request.method), c_string(&request.version));
        let mut status = 200;
        // SAFETY: the transaction lives only in this block; `messages`
        // outlives it, so the log callback's pointer stays valid.
        unsafe {
            let t = (api.new_transaction)(self.engine, self.rules, &mut messages as *mut Vec<String> as *mut c_void);
            let mut it = Intervention { status: 200, pause: 0, url: std::ptr::null_mut(), log: std::ptr::null_mut(), disruptive: 0 };
            let mut check = |t: *mut c_void, it: &mut Intervention, messages: &mut Vec<String>| -> bool {
                let hit = (api.intervention)(t, it) != 0 && it.disruptive != 0;
                // The rule that blocks reports here, not through the log callback.
                if !it.log.is_null() {
                    collect(messages as *mut Vec<String> as *mut c_void, it.log as *const c_void);
                }
                for pointer in [&mut it.url, &mut it.log] {
                    if !pointer.is_null() {
                        libc::free(*pointer as *mut c_void);
                        *pointer = std::ptr::null_mut();
                    }
                }
                hit
            };
            (api.process_connection)(t, client.as_ptr(), c_int::from(request.client_port), c"127.0.0.1".as_ptr(), c_int::from(request.server_port));
            (api.process_uri)(t, uri.as_ptr(), method.as_ptr(), version.as_ptr());
            for (name, value) in &request.headers {
                (api.add_n_request_header)(t, name.as_ptr(), name.len(), value.as_ptr(), value.len());
            }
            (api.process_request_headers)(t);
            if check(t, &mut it, &mut messages) {
                status = it.status;
            } else {
                if !request.body.is_empty() {
                    (api.append_request_body)(t, request.body.as_ptr(), request.body.len());
                }
                (api.process_request_body)(t);
                if check(t, &mut it, &mut messages) {
                    status = it.status;
                }
            }
            (api.process_logging)(t);
            (api.transaction_cleanup)(t);
        }
        Verdict { status: u16::try_from(status).ok().filter(|code| (100..600).contains(code)).unwrap_or(403), messages }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// Runs only where libmodsecurity 3 is installed; elsewhere there is nothing to call.
    #[test]
    fn a_rule_match_blocks_and_is_reported() {
        if !library_present() {
            eprintln!("libmodsecurity not installed; skipped");
            return;
        }
        let dir = std::env::temp_dir().join(format!("drust-modsec-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        let rules = dir.join("main.conf");
        std::fs::write(
            &rules,
            "SecRuleEngine On\nSecRequestBodyAccess On\n\
             SecRule REQUEST_HEADERS:Content-Type \"^application/x-www-form-urlencoded\" \"id:10,phase:1,pass,nolog,ctl:requestBodyProcessor=URLENCODED\"\n\
             SecRule ARGS \"@contains evil\" \"id:1001,phase:2,deny,status:403,log,msg:'Evil argument'\"\n",
        )
        .unwrap();
        let mut engine = ModSecurity::load(&rules).unwrap();

        let mut request = WafRequest {
            method: "POST".into(),
            uri: "/comment.php?page=1".into(),
            version: "1.1".into(),
            client_ip: "203.0.113.7".into(),
            client_port: 5000,
            server_port: 443,
            headers: vec![("Host".into(), "shop.test".into()), ("Content-Type".into(), "application/x-www-form-urlencoded".into())],
            body: b"comment=hello".to_vec(),
        };
        assert_eq!(engine.inspect(&request), Verdict { status: 200, messages: vec![] });

        request.body = b"comment=something+evil".to_vec();
        let verdict = engine.inspect(&request);
        assert_eq!(verdict.status, 403);
        assert!(verdict.messages.iter().any(|m| m.contains("Evil argument") && m.contains("1001")), "{verdict:?}");

        // A clean request after a blocked one starts afresh.
        request.uri = "/x?q=evil".into();
        request.body = b"comment=hello".to_vec();
        assert_eq!(engine.inspect(&request).status, 403);
        request.uri = "/x".into();
        assert_eq!(engine.inspect(&request).status, 200);

        std::fs::remove_dir_all(dir).unwrap();
    }
}
