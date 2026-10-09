"""Private Windows MDB worker. Run behind an authenticated HTTPS reverse proxy.

The protocol accepts reviewed values and a registered template identity, never paths or SQL.
Only Python standard library and installed Windows Access DAO are required.
"""
from __future__ import annotations

import base64
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import subprocess
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = Path(os.environ.get("MDB_WORKER_ROOT", "worker-data")).resolve()
REGISTRY = Path(os.environ.get("MDB_WORKER_TEMPLATES", "approved-templates.json")).resolve()
SECRET = os.environ.get("MDB_EXPORT_WORKER_SECRET", "").encode()
POWERSHELL = os.environ.get("MDB_EXPORT_POWERSHELL", str(Path(os.environ.get("WINDIR", r"C:\Windows")) / r"SysWOW64\WindowsPowerShell\v1.0\powershell.exe"))
WRITER = Path(__file__).with_name("export-reviewed-mdb.ps1").resolve()
MAX_INPUT = int(os.environ.get("MDB_WORKER_MAX_INPUT_BYTES", "10485760"))
MAX_OUTPUT = int(os.environ.get("MDB_EXPORT_MAX_BYTES", "104857600"))
TIMEOUT = int(os.environ.get("MDB_EXPORT_TIMEOUT", "180"))
LOCK = threading.Lock()  # DAO process serialization also limits resource exhaustion.


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def resolve_template(identity: dict) -> Path:
    registry = json.loads(REGISTRY.read_text(encoding="utf-8-sig"))
    key = identity.get("code", "") + ":" + identity.get("version", "")
    entry = registry.get(key)
    if not isinstance(entry, dict) or entry.get("sha256") != identity.get("sha256"):
        raise ValueError("Template identity is not registered as approved on this worker.")
    # This path comes from a local operator-owned registry, never a request property.
    template = Path(entry["path"]).resolve(strict=True)
    if template.suffix.lower() != ".mdb" or digest(template.read_bytes()) != entry["sha256"]:
        raise ValueError("Registered template SHA-256 differs.")
    if entry.get("synergee_version") != identity.get("synergee_version"):
        raise ValueError("Registered SynerGEE template version differs.")
    if entry.get("permitted_static_tables", []) != identity.get("permitted_static_tables", []):
        raise ValueError("Registered static library table allowlist differs.")
    return template


def export_model(body: bytes) -> dict:
    payload = json.loads(body)
    if payload.get("payload_version") != 1 or payload.get("mapping_version") != "synergee-reviewed-v1":
        raise ValueError("Unsupported export protocol version.")
    key = payload.get("idempotency_key", "")
    if not isinstance(key, str) or not re.fullmatch(r"[a-f0-9]{64}", key):
        raise ValueError("Invalid export identity.")
    if set(payload.get("tables", {})) != {"SAI_Control", "Node", "InstFeeders", "InstSection", "InstPrimaryTransformers", "Loads"}:
        raise ValueError("Invalid MDB table allowlist.")
    template = resolve_template(payload["template"])
    job_dir = (ROOT / key).resolve()
    if job_dir.parent != ROOT:
        raise ValueError("Invalid export directory.")
    job_dir.mkdir(parents=True, exist_ok=True)
    output = job_dir / "approved.mdb"
    metadata = job_dir / "result.json"
    payload_path = job_dir / "payload.json"
    body_hash = digest(body)
    if metadata.exists():
        result = json.loads(metadata.read_text(encoding="utf-8"))
        if result.get("payload_sha256") != body_hash:
            raise ValueError("Idempotency key is bound to a different approved payload.")
        if not output.is_file() or digest(output.read_bytes()) != result.get("output_sha256"):
            raise ValueError("Existing export artifact SHA-256 differs.")
    else:
        # Failed attempts retain their immutable payload identity, preventing key reuse.
        if payload_path.exists() and digest(payload_path.read_bytes()) != body_hash:
            raise ValueError("Idempotency key is bound to a different payload.")
        payload_path.write_bytes(body)
        if output.exists():
            output.unlink()  # Fixed direct child; no request-defined or recursive file operation.
        completed = subprocess.run([
            POWERSHELL, "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass",
            "-File", str(WRITER), "-InputPath", str(payload_path),
            "-TemplatePath", str(template), "-OutputPath", str(output),
        ], capture_output=True, timeout=TIMEOUT, check=False, encoding="utf-8", errors="replace")
        if completed.returncode:
            raise RuntimeError("MDB writer failed: " + completed.stderr.strip()[:4000])
        result = json.loads(completed.stdout.strip())
        result["payload_sha256"] = body_hash
        result["worker_completed_utc"] = time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())
        temp_metadata = job_dir / "result.pending.json"
        temp_metadata.write_text(json.dumps(result), encoding="utf-8")
        temp_metadata.replace(metadata)
    if output.stat().st_size > MAX_OUTPUT:
        raise ValueError("MDB output exceeds worker size limit.")
    result["mdb_base64"] = base64.b64encode(output.read_bytes()).decode("ascii")
    return result


class Handler(BaseHTTPRequestHandler):
    def do_POST(self) -> None:
        if self.path != "/v1/exports":
            self.respond(404, {"error": "Unknown endpoint."})
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if length <= 0 or length > MAX_INPUT:
                self.respond(413, {"error": "Invalid payload size."})
                return
            self.connection.settimeout(30)
            body = self.rfile.read(length)
            match = re.fullmatch(r"MDB-HMAC ([0-9]{10,12}):([a-f0-9]{64})", self.headers.get("Authorization", ""))
            if match is None or abs(time.time() - int(match[1])) > 300:
                self.respond(401, {"error": "Worker authentication failed."})
                return
            expected = hmac.new(SECRET, (match[1] + "\n" + digest(body)).encode(), hashlib.sha256).hexdigest()
            if not hmac.compare_digest(expected, match[2]):
                self.respond(401, {"error": "Worker authentication failed."})
                return
            with LOCK:
                result = export_model(body)
            self.respond(200, result)
        except (ValueError, KeyError, json.JSONDecodeError) as error:
            self.respond(422, {"error": str(error)[:4000]})
        except Exception as error:
            self.respond(503, {"error": str(error)[:4000]})

    def respond(self, status: int, value: dict) -> None:
        data = json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.send_header("X-MDB-Signature", hmac.new(SECRET, data, hashlib.sha256).hexdigest())
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, fmt: str, *args: object) -> None:
        # HTTP request logs contain no Authorization header or model payload.
        super().log_message(fmt, *args)


if __name__ == "__main__":
    if len(SECRET) < 32:
        raise SystemExit("Set MDB_EXPORT_WORKER_SECRET to a random secret of at least 32 characters.")
    if os.name != "nt" or not Path(POWERSHELL).is_file():
        raise SystemExit("This worker requires Windows and an installed Access DAO/ACE runtime.")
    if not REGISTRY.is_file():
        raise SystemExit("Register approved templates in MDB_WORKER_TEMPLATES first.")
    ROOT.mkdir(parents=True, exist_ok=True)
    # Loopback only. Expose through a firewall-restricted HTTPS reverse proxy.
    ThreadingHTTPServer(("127.0.0.1", int(os.environ.get("MDB_WORKER_PORT", "8766"))), Handler).serve_forever()
