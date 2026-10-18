# Not covered yet

The package covers what the GoSuccess API uses, plus the usual basics of buckets
and objects. To be added when needed:

- Creating and deleting buckets (the tests do it with raw signed requests)
- Bucket policies, lifecycle rules, CORS, tagging, ACLs
- Legal hold
- Server-side encryption settings (SSE-S3, SSE-KMS, SSE-C headers)
- Replication
- `CopyObject` and `UploadPartCopy`
- `SelectObjectContent`
- Checksums other than MD5 (`Content-MD5`) and SHA-256 (the signed payload hash),
  e.g. CRC32C
- Static website hosting
- Uploads from a file or stream (bodies are strings; large files go through
  multipart with parts the caller reads)
