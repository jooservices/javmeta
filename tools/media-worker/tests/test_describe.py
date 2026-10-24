import pytest
from jobs.describe import describe, process


def test_description_uses_image_and_preserves_structured_output():
    def fake(config, model, prompt, images):
        assert model == 'vision-local'
        assert images == ['cmVk']
        return {'description': 'A red frame.', 'tags': ['red'], 'confidence': .9}
    assert describe(b'red', {'vision_model': 'vision-local'}, fake) == {'description': 'A red frame.', 'tags': ['red'], 'confidence': .9}


@pytest.mark.parametrize('bad', [{}, {'description': 'x', 'tags': 'x', 'confidence': .5}, {'description': '', 'tags': [], 'confidence': .5}, {'description': 'x', 'tags': [], 'confidence': 2}])
def test_invalid_vision_json_retries_then_returns_uncertain_fallback(bad):
    calls = []
    def fake(*args, **kwargs): calls.append(1); return bad
    out = describe(b'generated', {'vision_model': 'vision'}, fake)
    assert len(calls) == 2
    assert out['confidence'] == 0
    assert out['tags'] == []


def test_missing_scenes_are_a_clear_failure():
    with pytest.raises(ValueError, match='Extract scenes'):
        process({'scenes': []}, {'output_folder': '/tmp'})
