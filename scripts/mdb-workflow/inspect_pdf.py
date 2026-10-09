"""Read-only PDF content check, used only with server-owned private source paths."""
import json
import sys
import fitz

with fitz.open(sys.argv[1]) as document:
    if not document.is_pdf or document.needs_pass or len(document) == 0:
        raise ValueError("Expected an unencrypted nonempty PDF")
    print(json.dumps({"page_count": len(document), "manual_verification_required": True}))
