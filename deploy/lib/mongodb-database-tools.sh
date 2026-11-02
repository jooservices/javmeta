#!/usr/bin/env bash

mongodb_database_tools_platform() {
  local version="$1"
  local architecture="$2"
  local ubuntu

  case "$version" in
    24.04) ubuntu='ubuntu2404' ;;
    26.04) ubuntu='ubuntu2604' ;;
    *) return 1 ;;
  esac

  case "$architecture" in
    amd64) architecture='x86_64' ;;
    arm64) architecture='arm64' ;;
    *) return 1 ;;
  esac

  printf '%s-%s\n' "$ubuntu" "$architecture"
}
