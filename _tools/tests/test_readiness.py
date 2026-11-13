"""_tools/readiness.py counts only code: a comment or a string's text is not a call of the app."""

import importlib.util
import pathlib
import tempfile
import unittest

_spec = importlib.util.spec_from_file_location("readiness", pathlib.Path(__file__).parent.parent / "readiness.py")
assert _spec is not None and _spec.loader is not None
readiness = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(readiness)


def calls(source: str) -> set[tuple[str, str]]:
    with tempfile.TemporaryDirectory() as folder:
        (pathlib.Path(folder) / "a.dart").write_text(source, encoding="utf-8")
        return readiness.app_calls(pathlib.Path(folder))


class AppCallsTest(unittest.TestCase):
    def test_a_comment_is_not_a_call(self) -> None:
        self.assertEqual(calls("// client.get('example')\n/// Api.client!.post('requests')\n/* x.get('a') /* y.get('b') */ */\n"), set())

    def test_a_string_s_text_is_not_a_call(self) -> None:
        self.assertEqual(calls("final s = \"say .get('example') to it\";\nfinal t = '''.post('x')''';\n"), set())

    def test_a_call_with_a_parameter_counts_with_it(self) -> None:
        source = "final r = await Api.client!.get('requests/${rawId(appId)}');\nawait c.post('preferences', form(w), key);\nawait c.delete('preferences/$id');\n"
        self.assertEqual(calls(source), {("GET", "requests/{}"), ("POST", "preferences"), ("DELETE", "preferences/{}")})

    def test_every_page_of_a_list_counts_as_its_get(self) -> None:
        self.assertEqual(calls("final all = await _all('requests/mine');\n"), {("GET", "requests/mine")})

    def test_a_url_in_a_string_does_not_hide_the_call_after_it(self) -> None:
        self.assertEqual(calls("final u = 'https://zon.sa'; final r = c.get('feed');\n"), {("GET", "feed")})


if __name__ == "__main__":
    unittest.main()


class RouteKeyTest(unittest.TestCase):
    def test_a_route_bound_by_a_field_is_the_one_the_test_log_writes(self) -> None:
        self.assertEqual(readiness.route_key("POST", "api/v1/customers/{customer:username}/follow"), "POST api/v1/customers/{customer}/follow")
        self.assertEqual(readiness.route_key("GET|HEAD", "api/v1/listings/{listing}"), "GET|HEAD api/v1/listings/{listing}")

