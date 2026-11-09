// S1 spike: Coraza v3 with the same rules and workload, in-process and as a sidecar.
package main

import (
	"bufio"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/corazawaf/coraza/v3"
	wasilibs "github.com/corazawaf/coraza-wasilibs"
	"github.com/corazawaf/coraza/v3/types"
)

type req struct {
	name, method, uri string
	headers           [][2]string
	body              []byte
}

func workload(path string) []req {
	data, err := os.ReadFile(path)
	if err != nil {
		panic(err)
	}
	var out []req
	for _, line := range strings.Split(strings.TrimSpace(string(data)), "\n") {
		f := strings.SplitN(line, "|", 5)
		h := [][2]string{
			{"Host", "shop.example.com"},
			{"User-Agent", "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36"},
			{"Accept", "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8"},
			{"Accept-Language", "en-US,en;q=0.9"},
			{"Accept-Encoding", "gzip, deflate, br"},
			{"Cookie", "wordpress_logged_in_abc=jane%7C1700000000%7Ctoken; wp-settings-1=libraryContent%3Dbrowse"},
			{"Connection", "keep-alive"},
		}
		if f[3] != "" {
			h = append(h, [2]string{"Content-Type", f[3]}, [2]string{"Content-Length", strconv.Itoa(len(f[4]))})
		}
		out = append(out, req{f[0], f[1], f[2], h, []byte(f[4])})
	}
	return out
}

func newWAF(rules string) coraza.WAF {
	waf, err := coraza.NewWAF(coraza.NewWAFConfig().WithDirectivesFromFile(rules))
	if err != nil {
		panic(err)
	}
	return waf
}

func status(it *types.Interruption) int {
	if it == nil {
		return 200
	}
	return it.Status
}

// One request through phases 1 and 2.
func inspect(waf coraza.WAF, method, uri string, headers [][2]string, body []byte) int {
	tx := waf.NewTransaction()
	defer func() { tx.ProcessLogging(); tx.Close() }()
	tx.ProcessConnection("203.0.113.7", 51234, "127.0.0.1", 443)
	tx.ProcessURI(uri, method, "HTTP/1.1")
	for _, h := range headers {
		tx.AddRequestHeader(h[0], h[1])
	}
	if it := tx.ProcessRequestHeaders(); it != nil {
		return status(it)
	}
	if len(body) > 0 {
		if it, _, _ := tx.WriteRequestBody(body); it != nil {
			return status(it)
		}
	}
	it, _ := tx.ProcessRequestBody()
	return status(it)
}

func rssMB() float64 {
	data, _ := os.ReadFile("/proc/self/status")
	for _, line := range strings.Split(string(data), "\n") {
		if strings.HasPrefix(line, "VmRSS:") {
			kb, _ := strconv.ParseFloat(strings.TrimSpace(strings.TrimSuffix(strings.TrimPrefix(line, "VmRSS:"), "kB")), 64)
			return kb / 1024
		}
	}
	return 0
}

func pct(t []time.Duration, p float64) float64 {
	return float64(t[int(float64(len(t)-1)*p)].Microseconds())
}

func main() {
	// RE2 for @rx, Aho-Corasick for @pm, as Coraza suggests for speed.
	wasilibs.RegisterRX()
	wasilibs.RegisterPM()
	wasilibs.RegisterSQLi()
	wasilibs.RegisterXSS()
	switch os.Args[1] {
	case "inproc":
		rules, reqs := os.Args[2], workload(os.Args[3])
		iters, _ := strconv.Atoi(os.Args[4])
		rss0, start := rssMB(), time.Now()
		waf := newWAF(rules)
		fmt.Printf("engine=coraza-re2 load_ms=%.0f rss_rules_mb=%.1f\n", float64(time.Since(start).Milliseconds()), rssMB()-rss0)
		for _, r := range reqs {
			for i := 0; i < 200; i++ {
				inspect(waf, r.method, r.uri, r.headers, r.body)
			}
		}
		for _, r := range reqs {
			times := make([]time.Duration, iters)
			st := 0
			for i := range times {
				t := time.Now()
				st = inspect(waf, r.method, r.uri, r.headers, r.body)
				times[i] = time.Since(t)
			}
			sort.Slice(times, func(a, b int) bool { return times[a] < times[b] })
			fmt.Printf("req=%s status=%d p50_us=%.0f p95_us=%.0f p99_us=%.0f\n", r.name, st, pct(times, .5), pct(times, .95), pct(times, .99))
		}
		for _, n := range strings.Split(os.Args[5], ",") {
			threads, _ := strconv.Atoi(n)
			t := time.Now()
			var wg sync.WaitGroup
			for w := 0; w < threads; w++ {
				wg.Add(1)
				go func() {
					defer wg.Done()
					for i := 0; i < iters; i++ {
						r := reqs[i%len(reqs)]
						inspect(waf, r.method, r.uri, r.headers, r.body)
					}
				}()
			}
			wg.Wait()
			fmt.Printf("mixed threads=%d req_per_s=%.0f\n", threads, float64(threads*iters)/time.Since(t).Seconds())
		}
		fmt.Printf("rss_end_mb=%.1f\n", rssMB())
	case "serve":
		// The gateway forwards the request line, headers and body as they came;
		// the answer is only a status: 200 let it through, else the block status.
		socket, waf := os.Args[2], newWAF(os.Args[3])
		os.Remove(socket)
		ln, err := net.Listen("unix", socket)
		if err != nil {
			panic(err)
		}
		fmt.Println("ready")
		http.Serve(ln, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			body, _ := io.ReadAll(r.Body)
			var headers [][2]string
			for k, vs := range r.Header {
				for _, v := range vs {
					headers = append(headers, [2]string{k, v})
				}
			}
			headers = append(headers, [2]string{"Host", r.Host})
			w.WriteHeader(inspect(waf, r.Method, r.RequestURI, headers, body))
		}))
	default:
		_ = bufio.NewReader
		panic("mode: inproc | serve")
	}
}
