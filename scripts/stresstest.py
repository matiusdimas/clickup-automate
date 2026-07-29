#!/usr/bin/env python3
"""
===============================================================================
ClickUp Automate System - High-Performance Stress & Load Testing Suite
===============================================================================
Multi-threaded load & stress tester for REST API performance benchmarking.
Uses Python 3 standard library (ThreadPoolExecutor). No pip install required.

Usage:
    python scripts/stresstest.py --url http://localhost:8000/api/clickup/tasks -c 20 -n 200
"""

import argparse
import concurrent.futures
import json
import os
import ssl
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Dict, List, Tuple

# ANSI Colors
GREEN = "\033[92m"
YELLOW = "\033[93m"
RED = "\033[91m"
BLUE = "\033[94m"
CYAN = "\033[96m"
BOLD = "\033[1m"
RESET = "\033[0m"

class StressTester:
    def __init__(self, target_url: str, token: str, concurrent_users: int, total_requests: int, method: str = "GET", payload: str = None):
        self.target_url = target_url
        self.token = token
        self.concurrent_users = concurrent_users
        self.total_requests = total_requests
        self.method = method.upper()
        self.payload = payload.encode('utf-8') if payload else None

        self.ctx = ssl.create_default_context()
        self.ctx.check_hostname = False
        self.ctx.verify_mode = ssl.CERT_NONE

    def single_request(self, req_id: int) -> Dict[str, Any]:
        headers = {
            "User-Agent": f"ClickUp-StressTester/1.0 (Worker-{req_id})",
            "Accept": "application/json"
        }
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"
        if self.payload and self.method in ["POST", "PUT", "PATCH"]:
            headers["Content-Type"] = "application/json"

        req = urllib.request.Request(self.target_url, data=self.payload, headers=headers, method=self.method)
        
        start = time.perf_counter()
        try:
            with urllib.request.urlopen(req, context=self.ctx, timeout=15) as resp:
                _ = resp.read()
                latency_ms = (time.perf_counter() - start) * 1000
                return {"status": resp.status, "latency": latency_ms, "error": None}
        except urllib.error.HTTPError as e:
            latency_ms = (time.perf_counter() - start) * 1000
            return {"status": e.code, "latency": latency_ms, "error": str(e)}
        except Exception as e:
            latency_ms = (time.perf_counter() - start) * 1000
            return {"status": 0, "latency": latency_ms, "error": str(e)}

    def calculate_percentile(self, sorted_latencies: List[float], percentile: float) -> float:
        if not sorted_latencies:
            return 0.0
        k = (len(sorted_latencies) - 1) * (percentile / 100.0)
        f = int(k)
        c = f + 1 if f + 1 < len(sorted_latencies) else f
        d0 = sorted_latencies[f] * (c - k)
        d1 = sorted_latencies[c] * (k - f)
        return d0 + d1

    def run(self):
        print(f"{BOLD}{CYAN}==============================================================================={RESET}")
        print(f"{BOLD}{CYAN}      CLICKUP AUTOMATE - HIGH PERFORMANCE STRESS TESTING SUITE                 {RESET}")
        print(f"{BOLD}{CYAN}==============================================================================={RESET}")
        print(f" Target Endpoint  : {self.target_url}")
        print(f" HTTP Method      : {self.method}")
        print(f" Concurrency      : {self.concurrent_users} parallel workers")
        print(f" Total Requests   : {self.total_requests}")
        print(f" API Token        : {'[CONFIGURED]' if self.token else '[NONE]'}")
        print(f" Starting load test at {time.strftime('%H:%M:%S')}...\n")

        results = []
        status_codes = {}
        error_count = 0
        
        start_test_time = time.perf_counter()

        with concurrent.futures.ThreadPoolExecutor(max_workers=self.concurrent_users) as executor:
            futures = [executor.submit(self.single_request, i) for i in range(self.total_requests)]
            
            completed = 0
            for future in concurrent.futures.as_completed(futures):
                res = future.result()
                results.append(res)
                completed += 1

                # Ticker progress output
                code = res["status"]
                status_codes[code] = status_codes.get(code, 0) + 1
                if code == 0 or code >= 500:
                    error_count += 1

                if completed % max(1, self.total_requests // 10) == 0 or completed == self.total_requests:
                    percent = (completed / self.total_requests) * 100
                    elapsed = time.perf_counter() - start_test_time
                    current_rps = completed / elapsed if elapsed > 0 else 0
                    sys.stdout.write(f"\r Progress: [{completed}/{self.total_requests}] {percent:5.1f}% | RPS: {current_rps:6.1f} req/s")
                    sys.stdout.flush()

        total_elapsed = time.perf_counter() - start_test_time
        print("\n\n" + f"{BOLD}{CYAN}Processing performance metrics...{RESET}")

        latencies = sorted([r["latency"] for r in results])
        avg_latency = sum(latencies) / len(latencies) if latencies else 0
        min_latency = latencies[0] if latencies else 0
        max_latency = latencies[-1] if latencies else 0

        p50 = self.calculate_percentile(latencies, 50)
        p90 = self.calculate_percentile(latencies, 90)
        p95 = self.calculate_percentile(latencies, 95)
        p99 = self.calculate_percentile(latencies, 99)

        overall_rps = self.total_requests / total_elapsed if total_elapsed > 0 else 0
        error_rate = (error_count / self.total_requests) * 100 if self.total_requests > 0 else 0

        # Health Assessment
        if error_rate == 0 and p95 < 500:
            health_status = f"{GREEN}[EXCELLENT]{RESET} Server handled load seamlessly with zero errors and fast response times."
        elif error_rate < 5.0 and p95 < 2000:
            health_status = f"{YELLOW}[GOOD / MODERATE]{RESET} Server handled load, but minor latency spikes detected under heavy concurrency."
        else:
            health_status = f"{RED}[CRITICAL / OVERLOADED]{RESET} Server experienced high error rates or unacceptable response latencies!"

        # Print Laporan Detailed
        print(f"\n{BOLD}{CYAN}==============================================================================={RESET}")
        print(f"{BOLD}{CYAN}                         STRESS TEST RESULTS & METRICS                         {RESET}")
        print(f"{BOLD}{CYAN}==============================================================================={RESET}")
        print(f" Total Duration      : {total_elapsed:.3f} seconds")
        print(f" Throughput (RPS)    : {BOLD}{CYAN}{overall_rps:.2f} req/sec{RESET}")
        print(f" Total Requests      : {self.total_requests}")
        print(f" Successful Requests : {self.total_requests - error_count}")
        print(f" Failed / Errored    : {error_count} ({error_rate:.2f}%)")
        
        print(f"\n {BOLD}HTTP Status Breakdown:{RESET}")
        for code, count in sorted(status_codes.items()):
            color = GREEN if 200 <= code < 300 else (YELLOW if 400 <= code < 500 else RED)
            print(f"   HTTP {color}{code:<4}{RESET} : {count} requests ({ (count/self.total_requests)*100:.1f}% )")

        print(f"\n {BOLD}Response Latency Distribution (Milliseconds):{RESET}")
        print(f"   Minimum Latency   : {min_latency:8.2f} ms")
        print(f"   Average Latency   : {avg_latency:8.2f} ms")
        print(f"   50th Percentile   : {p50:8.2f} ms  (Median)")
        print(f"   90th Percentile   : {p90:8.2f} ms")
        print(f"   95th Percentile   : {p95:8.2f} ms")
        print(f"   99th Percentile   : {p99:8.2f} ms")
        print(f"   Maximum Latency   : {max_latency:8.2f} ms")

        print(f"\n {BOLD}System Assessment:{RESET}")
        print(f"   {health_status}")
        print(f"{BOLD}{CYAN}==============================================================================={RESET}\n")

def read_token_from_env() -> str:
    env_path = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), ".env")
    if os.path.exists(env_path):
        try:
            with open(env_path, "r", encoding="utf-8") as f:
                for line in f:
                    if line.startswith("API_BEARER_TOKEN="):
                        return line.split("=", 1)[1].strip().strip('"').strip("'")
        except Exception:
            pass
    return ""

def main():
    parser = argparse.ArgumentParser(description="ClickUp Automate API Stress & Load Testing Tool")
    parser.add_argument("--url", default="http://localhost:8000/api/clickup/tasks", help="Target URL endpoint for stress testing")
    parser.add_argument("--token", default="", help="API Bearer Token (default: read from .env)")
    parser.add_argument("-c", "--concurrent", type=int, default=20, help="Number of concurrent workers (default: 20)")
    parser.add_argument("-n", "--requests", type=int, default=200, help="Total number of requests (default: 200)")
    parser.add_argument("--method", default="GET", help="HTTP Method (GET, POST, PUT, DELETE)")
    parser.add_argument("--body", default=None, help="JSON body payload for POST/PUT requests")
    args = parser.parse_args()

    token = args.token or read_token_from_env()
    tester = StressTester(
        target_url=args.url,
        token=token,
        concurrent_users=args.concurrent,
        total_requests=args.requests,
        method=args.method,
        payload=args.body
    )
    tester.run()

if __name__ == "__main__":
    main()
