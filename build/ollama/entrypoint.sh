#!/bin/sh
# Boot Ollama and ensure the configured model is present.
#
# The stock `ollama/ollama` image only runs the server; the weights have to be
# pulled separately. We pull once into the mounted volume (/root/.ollama), so the
# first `docker compose up` is demo-ready and every boot after that is instant.
set -e

MODEL="${OLLAMA_MODEL:-qwen2.5:3b-instruct}"

# Start the server in the background so we can talk to it to pull the model.
ollama serve &
server_pid=$!

echo "ollama: waiting for the server to accept connections..."
until ollama list >/dev/null 2>&1; do
  sleep 1
done

if ollama list | awk '{print $1}' | grep -qx "$MODEL"; then
  echo "ollama: model '$MODEL' already present (cached in the volume)."
else
  echo "ollama: pulling '$MODEL' (first boot only; this can take a while on CPU)..."
  ollama pull "$MODEL"
fi

echo "ollama: ready, serving '$MODEL'."
# Hand the container's lifetime back to the server process.
wait "$server_pid"
