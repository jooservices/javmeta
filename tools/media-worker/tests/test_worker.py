import pytest
import worker


def test_idle_worker_fetches_fresh_config_and_does_not_process(monkeypatch):
    calls = []
    def request(credentials, path, body=None):
        calls.append(path)
        return {'poll_sec': 7} if path == 'config' else None
    monkeypatch.setattr(worker, 'request', request)
    assert worker.step({}, 'test') == 7
    assert calls == ['config', 'jobs/next?worker=test']


def test_worker_reports_result_with_claim_and_sanitizes_failures(monkeypatch):
    calls = []
    def request(credentials, path, body=None):
        calls.append((path, body))
        if path == 'config': return {}
        if path.startswith('jobs/next'): return {'id': 5, 'claim_token': 'claim', 'kind': 'transcribe'}
    monkeypatch.setattr(worker, 'request', request)
    monkeypatch.setattr(worker, 'execute', lambda j, c: {'segment_count': 1})
    assert worker.step({}, 'test') == 0
    assert calls[-1] == ('jobs/5/complete', {'claim_token': 'claim', 'result': {'segment_count': 1}})
    def fail(j, c): raise RuntimeError('private source content')
    monkeypatch.setattr(worker, 'execute', fail)
    worker.step({}, 'test')
    assert calls[-1][0] == 'jobs/5/fail'
    assert 'private source content' not in calls[-1][1]['error']


def test_invalid_lease_is_rejected_before_starting_process():
    with pytest.raises(ValueError, match='Lease'):
        worker.execute({}, {'job_timeout_sec': 5, 'lease_seconds': 5})


@pytest.mark.parametrize('status', [422, 409, 500])
def test_rejected_completion_fails_only_validation_errors(monkeypatch, status):
    from urllib.error import HTTPError
    calls = []
    def request(credentials, path, body=None):
        calls.append((path, body))
        if path == 'config': return {}
        if path.startswith('jobs/next'): return {'id': 5, 'claim_token': 'claim'}
        if path.endswith('/complete'): raise HTTPError('private URL', status, 'private response', {}, None)
    monkeypatch.setattr(worker, 'request', request)
    monkeypatch.setattr(worker, 'execute', lambda *a: {'private': 'result'})
    if status != 422:
        with pytest.raises(HTTPError): worker.step({}, 'test')
        assert calls[-1][0] == 'jobs/5/complete'
    else:
        assert worker.step({}, 'test') == 0
        assert calls[-1][0] == 'jobs/5/fail'
        assert calls[-1][1]['claim_token'] == 'claim'
        assert 'private' not in str(calls[-1][1])
        assert 'result' not in calls[-1][1]
