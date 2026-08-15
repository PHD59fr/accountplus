"""HTTP checks for a local test deployment seeded by run.php."""

import argparse
import http.cookiejar
import re
import urllib.error
import urllib.parse
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument("--url", default="http://127.0.0.1:18080")
parser.add_argument("--missing-config", action="store_true")
args = parser.parse_args()
if urllib.parse.urlparse(args.url).hostname not in ("localhost", "127.0.0.1", "::1"):
    raise SystemExit("Use a local test deployment only.")


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


client = urllib.request.build_opener(
    urllib.request.ProxyHandler({}),
    urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
    NoRedirect(),
)


def request(path, values=None):
    data = urllib.parse.urlencode(values).encode() if values is not None else None
    try:
        response = client.open(args.url + path, data=data, timeout=10)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, response.headers, response.read().decode("utf-8")


for path in (
    "/.git/HEAD",
    "/%2egit/HEAD",
    "/app/config/database.php",
    "/app/storage/app/config.php",
    "/app/storage/exports/probe.compte.zip",
    "/config.php",
    "/tests/run.php",
    "/docker/entrypoint.sh",
    "/Dockerfile",
    "/docker-compose.yml",
    "/README.md",
    "/.dockerignore",
    "/public/index.php",
):
    status, _, _ = request(path)
    assert status in (403, 404), (path, status)
print("ok   private files are not served")

status, _, css = request("/assets/css/app.css")
assert status == 200 and ".chart-canvas" in css, "CSS was not served"
status, _, script = request("/assets/vendor/chart.js/chart.umd.min.js")
assert status == 200 and "Chart.js v4.4.1" in script, "Local Chart.js was not served"
print("ok   CSS and local Chart.js are served")

if args.missing_config:
    status, _, body = request("/setup")
    assert status in (403, 503), status
    status, _, _ = request("/setup", {"admin_username": "replacement"})
    assert status in (403, 503), "Setup was reopened after configuration loss"
    print("ok   existing database cannot be reset when configuration is missing")
else:
    status, headers, body = request("/login")
    assert status == 200, status
    csp = headers["Content-Security-Policy"]
    script_policy = re.search(r"script-src ([^;]+)", csp).group(1)
    assert "'nonce-" in script_policy and "'unsafe-inline'" not in script_policy
    csrf = re.search(r'name="_csrf_token" value="([^"]+)"', body).group(1)
    status, headers, _ = request("/login", {
        "_csrf_token": csrf, "username": "admin", "password": "integration-password",
    })
    assert status == 302 and headers["Location"] == "/", status
    for path in ("/", "/accounts", "/reports/transactions", "/admin/data"):
        status, _, body = request(path)
        assert status == 200, (path, status)
        assert 'src="/assets/vendor/chart.js/chart.umd.min.js?v=4.4.1"' in body
        assert "cdn.jsdelivr.net" not in body
    print("ok   login and main pages work with the nonce CSP and local charts")
    status, _, body = request("/admin/settings")
    assert status == 200, status
    assert 'action="/admin/settings/ip-restrictions"' in body
    assert 'name="ip_mode"' in body and 'name="ip_whitelist"' in body and 'name="ip_blacklist"' in body
    print("ok   administrator settings expose exclusive IP restriction modes")
