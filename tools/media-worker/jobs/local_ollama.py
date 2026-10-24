"""Local-only Ollama transport with no redirects or proxy environment use."""
import ipaddress
import json
import socket
from urllib.parse import urlsplit
from urllib.request import HTTPRedirectHandler, ProxyHandler, Request, build_opener


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ValueError('Local Ollama redirects are refused')


def local_url(url):
    parsed = urlsplit(url)
    if parsed.scheme not in ('http', 'https') or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise ValueError('Ollama must use the configured local HTTP endpoint')
    networks = [ipaddress.ip_network(n) for n in ('127.0.0.0/8', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '::1/128', 'fc00::/7')]
    addresses = socket.getaddrinfo(parsed.hostname, parsed.port or 80, type=socket.SOCK_STREAM)
    if not addresses or any(not any(ipaddress.ip_address(a[4][0]) in n for n in networks) for a in addresses):
        raise ValueError('Cloud endpoints are forbidden for media')
    return url.rstrip('/')


def chat(config, model, prompt, images=None):
    url = local_url(config['ollama_url']) + '/api/chat'
    message = {'role': 'user', 'content': prompt}
    if images:
        message['images'] = images
    body = {'model': model, 'stream': False, 'format': 'json', 'messages': [message],
            'options': {'temperature': 0}, 'keep_alive': 0}
    req = Request(url, data=json.dumps(body).encode(), headers={'Content-Type': 'application/json'})
    opener = build_opener(ProxyHandler({}), NoRedirect())
    with opener.open(req, timeout=int(config['ollama_timeout_sec'])) as response:
        data = json.load(response)
    value = json.loads(data['message']['content'])
    if not isinstance(value, dict):
        raise ValueError('Model response must be a JSON object')
    return value
