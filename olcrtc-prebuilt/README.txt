# Положите сюда готовый бинарь olcrtc (файл с именем `olcrtc`), чтобы сборка
# olcrtc-manager НЕ компилировала olcrtc из исходников. Пример на VDS:
#
#   mkdir -p /opt/xboard/olcrtc-prebuilt
#   docker cp "$(docker compose ps -q olcrtc-manager)":/usr/local/bin/olcrtc /opt/xboard/olcrtc-prebuilt/olcrtc
#
# Сам бинарь в git не коммитится (см. .gitignore).
