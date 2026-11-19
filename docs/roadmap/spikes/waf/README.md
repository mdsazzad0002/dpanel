# S1 harness

[../waf.md](../waf.md)-এর সংখ্যা এই file-গুলো দিয়ে মাপা। Build হয় repo-র বাইরে; এগুলো রাখা আছে আবার চালানোর জন্য।

| File | কী |
| --- | --- |
| `workload.txt` | ছয়টা request: `name\|method\|uri\|content-type\|body` |
| `modsec_bench.rs` | libmodsecurity 3, C API, in-process। Crate লাগে না; `-lmodsecurity` link করে |
| `coraza_bench.go` | Coraza v3 + `coraza-wasilibs` (RE2)। `inproc` মাপে, `serve <socket> <rules>` sidecar চালায়। `wasilibs.Register*` সরালে Go-র নিজের regexp |
| `sidecar_client.rs` | Gateway-র দিক: এক Unix socket connection-এ HTTP/1.1 keep-alive |

Rules: `Include <engine>.conf-recommended` (`SecRuleEngine On`, `SecResponseBodyAccess Off`, `SecAuditEngine Off`), তারপর CRS v4.30.0-এর `crs-setup.conf.example` ও `rules/*.conf`। libmodsecurity-র জন্য recommended config তার নিজের tag (`v3.0.14`) থেকে নিতে হয়; master-এর file 3.0.14-এ load হয় না।

```sh
./modsec_bench modsec-all.conf workload.txt 300 1,4,8
./coraza_bench inproc coraza-all.conf workload.txt 300 1,4,8
./coraza_bench serve /tmp/waf.sock coraza-all.conf & ./sidecar_client /tmp/waf.sock workload.txt 300 1,4,8
```
