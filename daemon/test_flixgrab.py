"""
FlixGrab Test Suite - Intelligent Download & Progressive Streaming Engine
Pruebas unitarias y de integración para el motor FlixGrab.
"""

import asyncio
import os
import sys
import unittest
from pathlib import Path

# Agregar directorio daemon al sys.path
DAEMON_DIR = Path(__file__).resolve().parent
sys.path.insert(0, str(DAEMON_DIR))

from flixgrab import DownloadTask, FlixGrabEngine, engine, init_app

class TestFlixGrabEngine(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        self.test_engine = FlixGrabEngine()

    def test_task_creation_and_defaults(self):
        task = DownloadTask({
            "job_id": "test-uuid-1234",
            "content_id": "movie-101",
            "title": "Big Buck Bunny",
            "source_url": "https://archive.org/download/BigBuckBunny_124/BigBuckBunny_1080p.mp4",
            "provider": "archive_org",
            "priority": 1,
            "quality": "1080p",
        })

        self.assertEqual(task.job_id, "test-uuid-1234")
        self.assertEqual(task.content_id, "movie-101")
        self.assertEqual(task.title, "Big Buck Bunny")
        self.assertEqual(task.priority, 1)
        self.assertEqual(task.status, "QUEUED")
        self.assertFalse(task.can_play_progressive)

        d = task.to_dict()
        self.assertEqual(d["job_id"], "test-uuid-1234")
        self.assertEqual(d["progress"], 0.0)
        self.assertIn("storage_path", d)

    def test_disk_space_check(self):
        disk = self.test_engine.get_disk_info()
        self.assertIn("free_bytes", disk)
        self.assertIn("has_space", disk)
        self.assertIn("free_formatted", disk)

    def test_job_registration_and_queue(self):
        task1 = DownloadTask({
            "job_id": "job-low",
            "title": "Low Priority Item",
            "priority": 10,
            "source_url": "https://example.com/low.mp4"
        })
        task2 = DownloadTask({
            "job_id": "job-high",
            "title": "High Priority Item",
            "priority": 1,
            "source_url": "https://example.com/high.mp4"
        })

        self.test_engine.register_job(task1)
        self.test_engine.register_job(task2)

        self.assertIn("job-low", self.test_engine.jobs)
        self.assertIn("job-high", self.test_engine.jobs)
        self.assertEqual(self.test_engine.queue.qsize(), 2)

    async def test_api_health_endpoint(self):
        from aiohttp.test_utils import TestClient, TestServer
        app = await init_app()
        client = TestClient(TestServer(app))
        await client.start_server()

        try:
            resp = await client.get("/health")
            self.assertEqual(resp.status, 200)
            data = await resp.json()
            self.assertEqual(data["status"], "healthy")
            self.assertEqual(data["service"], "FlixGrab Daemon")

            # Test metrics endpoint
            resp_m = await client.get("/metrics")
            self.assertEqual(resp_m.status, 200)
            data_m = await resp_m.json()
            self.assertTrue(data_m["success"])
            self.assertIn("metrics", data_m)

            # Test create job endpoint
            post_data = {
                "title": "Sita Sings the Blues",
                "source_url": "https://archive.org/download/Sita_Sings_the_Blues_1080p/Sita_Sings_the_Blues_1080p.mp4",
                "priority": 2,
                "provider": "archive_org"
            }
            resp_create = await client.post("/jobs", json=post_data)
            self.assertEqual(resp_create.status, 201)
            create_json = await resp_create.json()
            self.assertTrue(create_json["success"])
            self.assertIn("job", create_json)
            new_job_id = create_json["job"]["job_id"]

            # Test get job
            resp_get = await client.get(f"/jobs/{new_job_id}")
            self.assertEqual(resp_get.status, 200)
            get_json = await resp_get.json()
            self.assertEqual(get_json["job"]["title"], "Sita Sings the Blues")

            # Test update priority
            resp_prio = await client.post(f"/jobs/{new_job_id}/priority", json={"priority": 1})
            self.assertEqual(resp_prio.status, 200)
            prio_json = await resp_prio.json()
            self.assertEqual(prio_json["job"]["priority"], 1)

            # Test cancel job
            resp_cancel = await client.post(f"/jobs/{new_job_id}/cancel")
            self.assertEqual(resp_cancel.status, 200)
            cancel_json = await resp_cancel.json()
            self.assertEqual(cancel_json["job"]["status"], "CANCELLED")

        finally:
            await client.close()

if __name__ == "__main__":
    unittest.main()
