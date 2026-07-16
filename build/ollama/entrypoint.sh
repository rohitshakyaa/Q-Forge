#!/bin/sh
# Boot Ollama and ensure the configured models are present.
#
# The stock `ollama/ollama` image only runs the server; the weights have to be
# pulled separately. We pull once into the mounted volume (/root/.ollama), so the
# first `docker compose up` is demo-ready and every boot after that is instant.
#
# Two models: the generation LLM (M5, AI bank expansion) and the embedding model
# (M6, RAG — see docs/RAG-GUIDE.md). Same server serves both.
set -e

MODELS="${OLLAMA_MODEL:-qwen2.5:3b-instruct} ${OLLAMA_EMBED_MODEL:-nomic-embed-text}"

# Start the server in the background so we can talk to it to pull the models.
ollama serve &
server_pid=$!

echo "ollama: waiting for the server to accept connections..."
until ollama list >/dev/null 2>&1; do
  sleep 1
done

for MODEL in $MODELS; do
  if ollama list | awk '{print $1}' | grep -qx "$MODEL"; then
    echo "ollama: model '$MODEL' already present (cached in the volume)."
  else
    echo "ollama: pulling '$MODEL' (first boot only; this can take a while on CPU)..."
    ollama pull "$MODEL"
  fi
done

echo "ollama: ready, serving: $MODELS."
# Hand the container's lifetime back to the server process.
wait "$server_pid"
