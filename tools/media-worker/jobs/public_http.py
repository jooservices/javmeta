"""Anonymous public-only transport shared by discovery and ffmpeg's local relay."""
import ipaddress
import secrets
import socket
import ssl
import threading
from contextlib import contextmanager
from http.client import HTTPConnection
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urljoin, urlsplit, urlunsplit


def public_addresses(host, port):
    addresses = socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)
    if not addresses:
        raise ValueError('Capture destination has no addresses')
    for _, _, _, _, address in addresses:
        ip = ipaddress.ip_address(address[0])
        # Reject all nonpublic space, including CGNAT, metadata and IPv6 transition addresses.
        if (str(ip) == '168.63.129.16' or not ip.is_global or ip.is_multicast or ip.is_reserved or ip.is_loopback
                or ip.is_link_local or ip.is_unspecified or '%' in address[0]
                or getattr(ip, 'ipv4_mapped', None) or getattr(ip, 'sixtofour', None)
                or getattr(ip, 'teredo', None)):
            raise ValueError('Capture destination must be a public Internet address')
    return addresses


def http_url(url):
    p = urlsplit(url)
    if (p.scheme not in ('http', 'https') or not p.hostname or p.username is not None
            or p.password is not None or any(ord(c) <= 32 or ord(c) == 127 for c in url)
            or '\\' in url or '%' in p.hostname):
        raise ValueError('Only anonymous HTTP(S) capture is supported')
    public_addresses(p.hostname, p.port or (443 if p.scheme == 'https' else 80))
    return url


@contextmanager
def open_public(url, timeout, headers=None):
    for _ in range(6):
        http_url(url)
        p = urlsplit(url)
        port = p.port or (443 if p.scheme == 'https' else 80)
        # Validate the complete DNS answer, then connect to that exact sockaddr: no second lookup.
        addresses = public_addresses(p.hostname, port)
        family, socktype, protocol, _, address = addresses[0]
        connection = HTTPConnection(p.hostname, port, timeout=timeout)
        sock = socket.socket(family, socktype, protocol)
        try:
            sock.settimeout(timeout)
            sock.connect(address)
            if p.scheme == 'https':
                sock = ssl.create_default_context().wrap_socket(sock, server_hostname=p.hostname)
            connection.sock = sock
            connection.request('GET', urlunsplit(('', '', p.path or '/', p.query, '')),
                               headers={'User-Agent': 'JavMeta-local-capture', **(headers or {})})
            response = connection.getresponse()
            if response.status in (301, 302, 303, 307, 308):
                location = response.getheader('Location')
                if not location:
                    raise ValueError('Capture redirect has no destination')
                url = http_url(urljoin(url, location))
                continue
            if response.status not in (200, 206):
                raise ValueError('Capture resource is unavailable')
            yield response, url
            return
        finally:
            connection.close()
            sock.close()
    raise ValueError('Too many capture redirects')


@contextmanager
def media_relay(config):
    origins = {}

    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def do_GET(self):
            token, separator, resource = self.path.lstrip('/').partition('/')
            if not separator or token not in origins:
                self.send_error(404)
                return
            try:
                headers = {'Range': self.headers['Range']} if self.headers.get('Range') else {}
                with open_public(origins[token] + '/' + resource,
                                 int(config['capture']['http_timeout_sec']), headers) as (response, _):
                    self.send_response(response.status)
                    for name in ('Content-Length', 'Content-Type', 'Content-Range', 'Accept-Ranges'):
                        if response.getheader(name):
                            self.send_header(name, response.getheader(name))
                    self.end_headers()
                    remaining = int(config['capture']['max_bytes'])
                    while remaining > 0:
                        chunk = response.read(min(65536, remaining))
                        if not chunk:
                            break
                        self.wfile.write(chunk)
                        remaining -= len(chunk)
            except Exception:
                # Never expose a source URL or upstream response body to ffmpeg logs.
                self.close_connection = True

    server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()

    def rewrite(url):
        p = urlsplit(http_url(url))
        origin = urlunsplit((p.scheme, p.netloc, '', '', ''))
        token = next((key for key, value in origins.items() if value == origin), None)
        if token is None:
            token = secrets.token_hex(24)
            origins[token] = origin
        return urlunsplit(('http', f'127.0.0.1:{server.server_port}',
                          '/' + token + (p.path or '/'), p.query, ''))

    try:
        yield rewrite
    finally:
        server.shutdown()
        server.server_close()
        thread.join()
