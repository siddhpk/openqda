import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import build


class FrontendBuildTest(unittest.TestCase):
    def test_installs_composer_dependencies_before_build_on_clean_checkout(self):
        with tempfile.TemporaryDirectory() as tmp:
            web = Path(tmp)
            ziggy = web / "vendor" / "tightenco" / "ziggy" / "dist" / "vue.m.js"
            manifest = web / "public" / "build" / "manifest.json"
            commands = []

            def run(command, cwd=None, env=None):
                commands.append(command)
                if command[0] == "composer":
                    ziggy.parent.mkdir(parents=True)
                    ziggy.touch()
                elif command[:3] == ["npm", "run", "build"]:
                    manifest.parent.mkdir(parents=True)
                    manifest.write_text("{}")

            with patch.object(build, "WEB", web), patch.object(build, "MANIFEST", manifest), \
                    patch.object(build, "FRONTEND_MARK", manifest.parent / ".desktop-build"), \
                    patch.object(build, "run", side_effect=run):
                build.build_frontend(False)

            self.assertEqual([command[0] for command in commands], ["composer", "npm", "npm"])
            self.assertTrue((manifest.parent / ".desktop-build").is_file())

    def test_existing_ziggy_does_not_reinstall_composer_dependencies(self):
        with tempfile.TemporaryDirectory() as tmp:
            web = Path(tmp)
            ziggy = web / "vendor" / "tightenco" / "ziggy" / "dist" / "vue.m.js"
            ziggy.parent.mkdir(parents=True)
            ziggy.touch()
            manifest = web / "public" / "build" / "manifest.json"
            manifest.parent.mkdir(parents=True)
            manifest.write_text("{}")
            with patch.object(build, "WEB", web), patch.object(build, "MANIFEST", manifest), \
                    patch.object(build, "FRONTEND_MARK", manifest.parent / ".desktop-build"), \
                    patch.object(build, "run") as run:
                build.build_frontend(False)
            self.assertEqual([call.args[0][0] for call in run.call_args_list], ["npm", "npm"])

    def test_fails_if_composer_does_not_install_ziggy(self):
        with tempfile.TemporaryDirectory() as tmp:
            with patch.object(build, "WEB", Path(tmp)), patch.object(build, "run") as run:
                with self.assertRaisesRegex(SystemExit, "Composer did not install the Ziggy"):
                    build.build_frontend(False)
            self.assertEqual(run.call_count, 1)


if __name__ == "__main__":
    unittest.main()
