.SILENT: run

run:
	docker compose up -d --build && sleep 1 && docker logs php-request-proxy
	echo "Started Server on http://localhost:8888"