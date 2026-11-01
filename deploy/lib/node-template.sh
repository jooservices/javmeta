#!/usr/bin/env bash
# Render only known placeholders, without eval or sed replacement interpretation.
render_node_template() {
  local source_file="$1" destination="$2" key value content
  content="$(cat "$source_file")"
  for key in TARGET_DIR SERVICE_USER UNIT_PREFIX GATEKEEPER_ADDRESS UI_HOST CC_HOST PHP_BINARY EMBEDDER_BIND EMBEDDER_PORT; do
    value="${!key}"
    [[ "$value" != *$'\n'* && "$value" != *$'\r'* ]] || return 1
    content="${content//@$key@/$value}"
  done
  [[ "$content" != *@[A-Z_]*@* ]] || return 1
  printf '%s\n' "$content" > "$destination"
  chmod 644 "$destination"
}
