# Synthetic benchmark

WordPress 7.1 / PHP 8.2.27 / MariaDB 10.6.23 on this Windows workstation. Each fresh process loads WordPress before measurement, then performs 30 filter calls, ten real SELECT queries and one short-circuited HTTP fixture. Collection and storage finalization are included; WordPress bootstrap is excluded. SAVEQUERIES is externally enabled for every mode. Twenty measured samples per mode follow two warmups. Empirical p99 with twenty samples is the maximum, not a stable production estimate.

| Mode | p50 ms | p95 ms | p99 ms | p95 extra memory KiB | Estimated KiB / 1,000 workloads |
|---|---:|---:|---:|---:|---:|
| off | 12.229 | 117.210 | 507.390 | 6.8 | 0.0 |
| http | 96.995 | 213.681 | 437.053 | 73.7 | 2688.2 |
| bodies | 91.597 | 339.328 | 589.788 | 73.8 | 2731.0 |
| database | 279.674 | 695.086 | 1053.424 | 113.5 | 7339.3 |
| errors | 69.454 | 235.391 | 263.812 | 82.5 | 2989.4 |
| hooks | 532.002 | 1883.368 | 2075.142 | 127.6 | 9771.2 |

These are reproducible local fixture results, not a guarantee of website overhead. HTTP transport latency, real workloads, database engines, persistent caches and profiler combinations require their own measurements. Raw samples include additional query counts and stored byte estimates.
