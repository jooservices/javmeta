from types import SimpleNamespace

from jobs.subtitles import render, timestamp, write
from jobs.transcribe import recognize


def test_recognition_uses_vad_and_detected_language():
    class Fake:
        def transcribe(self, path, **kwargs):
            assert kwargs == {'vad_filter': True}
            return iter([SimpleNamespace(start=0.1, end=2.4, text=' hello ')]), SimpleNamespace(language='en')
    assert recognize('generated.wav', {}, Fake()) == ('en', [{'start': .1, 'end': 2.4, 'text': 'hello'}])


def test_subtitle_formats_preserve_timing_and_escape_vtt(tmp_path):
    segments = [{'start': 1.25, 'end': 3661.999, 'text': '<b>Hello</b>'}]
    assert timestamp(3661.999) == '01:01:01,999'
    assert '00:00:01,250 --> 01:01:01,999' in render(segments)
    vtt = render(segments, True)
    assert vtt.startswith('WEBVTT\n')
    assert '00:00:01.250 --> 01:01:01.999' in vtt
    assert '&lt;b&gt;Hello&lt;/b&gt;' in vtt
    rows = write(tmp_path, 'en', segments, 'whisper')
    assert len(rows) == 2
    assert rows[0]['source'] == 'whisper'
    assert (tmp_path / 'subtitles.en.vtt').read_text() == vtt


def test_process_writes_detected_language_transcript_and_metrics(tmp_path, monkeypatch):
    import json
    from jobs import transcribe
    source = tmp_path / 'generated.wav'
    source.write_bytes(b'fixture')
    monkeypatch.setattr(transcribe, 'recognize', lambda p, c: ('en', [{'start': 0, 'end': 1, 'text': 'Hello'}]))
    monkeypatch.setattr(transcribe, 'duration', lambda p, c: 2)
    result = transcribe.process({'id': 5, 'path': source}, {'output_folder': str(tmp_path / 'output')})
    assert result['language'] == 'en'
    assert result['segment_count'] == 1
    assert result['real_time_factor'] >= 0
    assert json.loads(__import__('pathlib').Path(result['transcript_path']).read_text())['segments'][0]['text'] == 'Hello'


def test_silence_fails_instead_of_creating_empty_subtitles(tmp_path, monkeypatch):
    import pytest
    from jobs import transcribe
    source = tmp_path / 'silent.wav'
    source.touch()
    monkeypatch.setattr(transcribe, 'recognize', lambda p, c: ('en', []))
    with pytest.raises(ValueError, match='No speech'):
        transcribe.process({'id': 5, 'path': source}, {})
