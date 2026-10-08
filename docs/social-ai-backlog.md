# Social AI Follow-Ups

- Deferred October 7, 2026: replace inbound calls to the home PC with an authenticated outbound worker. WordPress keeps durable generation jobs; the PC claims jobs over HTTPS, runs local Ollama, and returns revision-checked results. Offline jobs wait instead of exhausting short retries. Keep publishing independent of PC availability, preserve manual edits, and add health reporting. Remove the inbound Ollama port forward after migration.
