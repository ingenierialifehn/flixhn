import unittest
from stream_fetcher import is_safe_url, HlsManifestParser

class TestStreamFetcher(unittest.TestCase):

    def test_ssrf_blocks_private_ips(self):
        # Loopback
        safe, reason = is_safe_url("http://127.0.0.1/admin", allow_internal=False)
        self.assertFalse(safe)
        self.assertIn("rango privado", reason)

        # Cloud metadata
        safe, reason = is_safe_url("http://169.254.169.254/latest/meta-data", allow_internal=False)
        self.assertFalse(safe)

        # RFC1918 Class A
        safe, reason = is_safe_url("http://10.10.1.1/secret", allow_internal=False)
        self.assertFalse(safe)

        # RFC1918 Class C
        safe, reason = is_safe_url("http://192.168.1.50/stream.m3u8", allow_internal=False)
        self.assertFalse(safe)

        # Invalid schemes
        safe, reason = is_safe_url("file:///etc/passwd")
        self.assertFalse(safe)
        self.assertIn("Esquema no permitido", reason)

        safe, reason = is_safe_url("gopher://localhost:70")
        self.assertFalse(safe)

    def test_ssrf_allows_public_https(self):
        safe, reason = is_safe_url("https://example.com/playlist.m3u8")
        self.assertTrue(safe)
        self.assertIsNone(reason)

    def test_parse_master_manifest(self):
        sample_master = """#EXTM3U
#EXT-X-VERSION:3
#EXT-X-STREAM-INF:BANDWIDTH=800000,RESOLUTION=640x360,CODECS="avc1.4d401e,mp4a.40.2"
360p.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=1400000,RESOLUTION=842x480,CODECS="avc1.4d401f,mp4a.40.2"
480p.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=2800000,RESOLUTION=1280x720,CODECS="avc1.4d401f,mp4a.40.2"
720p.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=5000000,RESOLUTION=1920x1080,CODECS="avc1.640028,mp4a.40.2"
1080p.m3u8
"""
        parsed = HlsManifestParser.parse_m3u8_content(sample_master, "https://cdn.flixhn.com/hls/master.m3u8")
        self.assertTrue(parsed["valid"])
        self.assertEqual(parsed["type"], "master")
        self.assertEqual(len(parsed["variants"]), 4)
        self.assertEqual(parsed["variants"][3]["resolution"], "1920x1080")
        self.assertEqual(parsed["variants"][3]["bandwidth"], 5000000)
        self.assertEqual(parsed["variants"][3]["url"], "https://cdn.flixhn.com/hls/1080p.m3u8")

    def test_parse_media_manifest(self):
        sample_media = """#EXTM3U
#EXT-X-VERSION:3
#EXT-X-TARGETDURATION:6
#EXT-X-MEDIA-SEQUENCE:0
#EXTINF:5.005,
segment_000.ts
#EXTINF:4.995,
segment_001.ts
#EXTINF:6.000,
segment_002.ts
#EXT-X-ENDLIST
"""
        parsed = HlsManifestParser.parse_m3u8_content(sample_media, "https://cdn.flixhn.com/hls/1080p.m3u8")
        self.assertTrue(parsed["valid"])
        self.assertEqual(parsed["type"], "media")
        self.assertEqual(parsed["target_duration"], 6.0)
        self.assertEqual(len(parsed["segments"]), 3)
        self.assertEqual(parsed["segments"][0]["duration"], 5.005)
        self.assertEqual(parsed["segments"][0]["url"], "https://cdn.flixhn.com/hls/segment_000.ts")

if __name__ == "__main__":
    unittest.main()
