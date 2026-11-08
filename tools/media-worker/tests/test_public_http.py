import socket
from unittest.mock import Mock

import pytest

from jobs import public_http
from jobs.capture import fetch


@pytest.mark.parametrize('ip', ['127.0.0.1', '10.1.2.3', '172.16.1.1', '192.168.1.1', '169.254.169.254',
                                    '100.100.100.200', '168.63.129.16', '0.0.0.0', '224.0.0.1', '::1', 'fe80::1', 'fc00::1',
                                    '::ffff:127.0.0.1', '2002:7f00:1::', '192.0.0.1'])
def test_private_and_metadata_dns_answers_are_refused(monkeypatch, ip):
    monkeypatch.setattr(socket, 'getaddrinfo', lambda *a, **k: [(socket.AF_INET, socket.SOCK_STREAM, 6, '', (ip, 80))])
    with pytest.raises(ValueError, match='public Internet'):
        public_http.http_url('http://public.example/playlist')


@pytest.mark.parametrize('url', ['file:///etc/passwd', 'http://user:pass@8.8.8.8/', 'http://8.8.8.8\\@127.0.0.1/',
                                 'http://[fe80::1%25eth0]/', 'http://8.8.8.8/\nheader'])
def test_invalid_urls_are_refused(url):
    with pytest.raises(ValueError):
        public_http.http_url(url)


def test_mixed_public_and_private_dns_answer_is_refused(monkeypatch):
    monkeypatch.setattr(socket, 'getaddrinfo', lambda *a, **k: [
        (socket.AF_INET, socket.SOCK_STREAM, 6, '', (ip, 80)) for ip in ['8.8.8.8', '127.0.0.1']])
    with pytest.raises(ValueError):
        public_http.http_url('http://mixed.example/')


def test_socket_is_pinned_and_https_preserves_host_and_certificate_name(monkeypatch):
    addresses = [(socket.AF_INET, socket.SOCK_STREAM, 6, '', ('8.8.8.8', 443))]
    monkeypatch.setattr(public_http, 'public_addresses', lambda *a: addresses)
    sock = Mock()
    monkeypatch.setattr(socket, 'socket', lambda *a: sock)
    context = Mock()
    monkeypatch.setattr(public_http.ssl, 'create_default_context', lambda: context)
    connection = Mock()
    response = Mock(status=200)
    connection.getresponse.return_value = response
    monkeypatch.setattr(public_http, 'HTTPConnection', lambda *a, **k: connection)
    with public_http.open_public('https://cdn.example/video?token=x', 5) as (result, final):
        assert result is response
        assert final == 'https://cdn.example/video?token=x'
    sock.connect.assert_called_once_with(('8.8.8.8', 443))
    context.wrap_socket.assert_called_once_with(sock, server_hostname='cdn.example')
    assert connection.request.call_args.args[:2] == ('GET', '/video?token=x')
    connection.close.assert_called_once()


def test_rebinding_at_connect_is_refused(monkeypatch):
    answers = iter(['8.8.8.8', '127.0.0.1'])
    monkeypatch.setattr(socket, 'getaddrinfo', lambda *a, **k: [
        (socket.AF_INET, socket.SOCK_STREAM, 6, '', (next(answers), 80))])
    sock = Mock()
    monkeypatch.setattr(socket, 'socket', lambda *a: sock)
    with pytest.raises(ValueError):
        with public_http.open_public('http://rebind.example/', 5):
            pass
    sock.connect.assert_not_called()


def test_redirect_to_metadata_is_refused_before_second_connection(monkeypatch):
    monkeypatch.setattr(socket, 'getaddrinfo', lambda host, *a, **k: [
        (socket.AF_INET, socket.SOCK_STREAM, 6, '', ('169.254.169.254' if host == 'metadata.example' else '8.8.8.8', 80))])
    sock = Mock()
    monkeypatch.setattr(socket, 'socket', lambda *a: sock)
    connection = Mock()
    response = Mock(status=302)
    response.getheader.return_value = 'http://metadata.example/latest/meta-data/'
    connection.getresponse.return_value = response
    monkeypatch.setattr(public_http, 'HTTPConnection', lambda *a, **k: connection)
    with pytest.raises(ValueError):
        fetch('http://public.example/', {'capture': {'http_timeout_sec': 5, 'max_manifest_bytes': 100}})
    sock.connect.assert_called_once()
    connection.close.assert_called_once()


def test_relay_rechecks_segment_dns_after_manifest_preparation(monkeypatch):
    from http.client import RemoteDisconnected
    from urllib.request import urlopen
    state = {'ip': '8.8.8.8'}
    original = socket.getaddrinfo
    def resolve(host, port, *args, **kwargs):
        if host == 'cdn.example':
            return [(socket.AF_INET, socket.SOCK_STREAM, 6, '', (state['ip'], port))]
        return original(host, port, *args, **kwargs)
    monkeypatch.setattr(socket, 'getaddrinfo', resolve)
    config = {'capture': {'http_timeout_sec': 2, 'max_bytes': 1024}}
    with public_http.media_relay(config) as rewrite:
        segment = rewrite('http://cdn.example/seg-1.ts')
        assert segment.startswith('http://127.0.0.1:')
        state['ip'] = '169.254.169.254'
        with pytest.raises(RemoteDisconnected):
            urlopen(segment, timeout=3)


def test_public_relative_redirect_is_followed_with_a_new_pinned_connection(monkeypatch):
    monkeypatch.setattr(public_http, 'public_addresses', lambda *a: [
        (socket.AF_INET, socket.SOCK_STREAM, 6, '', ('8.8.8.8', 80))])
    sockets = []
    def make_socket(*args):
        sock = Mock()
        sockets.append(sock)
        return sock
    monkeypatch.setattr(socket, 'socket', make_socket)
    redirect, final = Mock(status=302), Mock(status=200)
    redirect.getheader.return_value = '/final.m3u8'
    responses = iter([redirect, final])
    connection = Mock()
    connection.getresponse.side_effect = lambda: next(responses)
    monkeypatch.setattr(public_http, 'HTTPConnection', lambda *a, **k: connection)
    with public_http.open_public('http://cdn.example/start', 5) as (response, url):
        assert response is final
        assert url == 'http://cdn.example/final.m3u8'
    assert len(sockets) == 2
    for sock in sockets:
        sock.connect.assert_called_once_with(('8.8.8.8', 80))
        sock.close.assert_called_once()
