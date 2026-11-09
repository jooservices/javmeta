from embedder.prefix import prefix_for_model


def test_query_model_gets_query_prefix() -> None:
    assert prefix_for_model("javmeta-query") == "query: "


def test_passage_model_gets_passage_prefix() -> None:
    assert prefix_for_model("javmeta-passage") == "passage: "
