#!/usr/bin/env sh
# Regenerates the zstd interoperability fixtures from external Parquet writers.
# Requires: duckdb CLI, uv (for pyarrow). Run from this directory.
#
# Every file holds the same 5000 rows:
#   id        int64            0..4999
#   half      double           id * 0.5
#   category  string           'cat_' || (id % 10)   (low cardinality -> dictionary encoded)
#   maybe     int32, nullable  NULL when id % 7 = 0, otherwise id
set -e
cd "$(dirname "$0")"

duckdb -c "
COPY (
  SELECT
    i::BIGINT AS id,
    (i * 0.5)::DOUBLE AS half,
    'cat_' || (i % 10) AS category,
    CASE WHEN i % 7 = 0 THEN NULL ELSE i::INTEGER END AS maybe
  FROM range(5000) t(i)
  ORDER BY i
) TO 'duckdb.zstd.parquet' (FORMAT parquet, COMPRESSION zstd, ROW_GROUP_SIZE 2048);
"

uv run --no-project --with pyarrow python - <<'PY'
import pyarrow as pa
import pyarrow.parquet as pq

n = 5000
table = pa.table({
    "id": pa.array(range(n), pa.int64()),
    "half": pa.array([i * 0.5 for i in range(n)], pa.float64()),
    "category": pa.array([f"cat_{i % 10}" for i in range(n)], pa.string()),
    "maybe": pa.array([None if i % 7 == 0 else i for i in range(n)], pa.int32()),
})
pq.write_table(table, "pyarrow.zstd.parquet", compression="zstd", row_group_size=2000)
pq.write_table(table, "pyarrow.datapagev2.zstd.parquet", compression="zstd",
               row_group_size=2000, data_page_version="2.0")
print("pyarrow", pa.__version__)
PY
