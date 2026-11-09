def prefix_for_model(model: str) -> str:
    return "query: " if "query" in model.lower() else "passage: "
