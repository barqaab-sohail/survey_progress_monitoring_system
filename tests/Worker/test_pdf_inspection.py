import hashlib
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import pymupdf


INSPECTOR = Path(__file__).resolve().parents[2] / "scripts/mdb-workflow/inspect_pdf.py"


class PdfInspectionTest(unittest.TestCase):
    def inspect(self, content):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "survey.pdf"
            source.write_bytes(content)
            before = hashlib.sha256(content).digest()
            result = subprocess.run(
                [sys.executable, str(INSPECTOR), str(source)],
                capture_output=True, text=True, timeout=20,
            )
            self.assertEqual(hashlib.sha256(source.read_bytes()).digest(), before)
            return result

    def test_valid_pdf_outputs_only_json_and_preserves_original(self):
        with pymupdf.open() as document:
            document.new_page()
            result = self.inspect(document.tobytes())
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), {
            "page_count": 1, "manual_verification_required": True,
        })

    def test_password_protected_pdf_is_rejected(self):
        with pymupdf.open() as document:
            document.new_page()
            result = self.inspect(document.tobytes(
                encryption=pymupdf.PDF_ENCRYPT_AES_256,
                owner_pw="owner", user_pw="operator",
            ))
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, "")
        self.assertIn("Expected an unencrypted nonempty PDF", result.stderr)

    def test_non_pdf_is_rejected(self):
        result = self.inspect(b"Invalid PDF content")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(result.stdout, "")


if __name__ == "__main__":
    unittest.main()
