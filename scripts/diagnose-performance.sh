#!/usr/bin/env bash
# Diagnostic de lenteur, à lancer sur le serveur depuis la racine de l'application :
#   bash scripts/diagnose-performance.sh https://clinique-abm.rivo.mg
# Il ne modifie rien, sauf un fichier temporaire de test dans public/, supprimé aussitôt.
set -u
URL="${1:?Donnez l adresse du site, par exemple https://clinique-abm.rivo.mg}"
HOST="$(echo "$URL" | sed -E 's#https?://([^/]+).*#\1#')"

echo "== 1. Temps du serveur seul (sans le réseau Madagascar–France)"
for i in 1 2 3; do
  curl -s -o /dev/null --resolve "$HOST:443:127.0.0.1" \
    -w "   /login  TTFB %{time_starttransfer}s\n" "$URL/login"
done

echo "== 2. OPcache côté web (le PHP du terminal n'est pas celui du site)"
PROBE="public/opcache-probe-$RANDOM$RANDOM.php"
cat > "$PROBE" <<'PHP'
<?php
$s = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
echo 'PHP ', PHP_VERSION, ' | SAPI ', PHP_SAPI, "\n";
echo 'OPcache actif : ', ($s && $s['opcache_enabled']) ? 'OUI' : 'NON', "\n";
if ($s) {
    echo 'Mémoire utilisée : ', round($s['memory_usage']['used_memory'] / 1048576), ' Mo / libre ',
        round($s['memory_usage']['free_memory'] / 1048576), " Mo\n";
    echo 'Scripts en cache : ', $s['opcache_statistics']['num_cached_scripts'], ' (max ',
        ini_get('opcache.max_accelerated_files'), ")\n";
    echo 'Taux de succès : ', round($s['opcache_statistics']['opcache_hit_rate'], 1), " %\n";
    echo 'validate_timestamps : ', ini_get('opcache.validate_timestamps'), ' | revalidate_freq : ',
        ini_get('opcache.revalidate_freq'), "\n";
}
echo 'realpath_cache_size : ', ini_get('realpath_cache_size'), "\n";
PHP
curl -s --resolve "$HOST:443:127.0.0.1" "$URL/$(basename "$PROBE")" | sed 's/^/   /'
rm -f "$PROBE"

echo "== 3. Caches Laravel (config, routes, events, vues) et pilotes"
php artisan about --only=environment,cache,drivers 2>/dev/null | sed 's/^/   /'

echo "== 4. Latence de la base de données (100 requêtes)"
php artisan tinker --execute='
$t = microtime(true); for ($i = 0; $i < 100; $i++) { DB::select("select 1"); }
printf("   %.2f ms par requête\n", (microtime(true) - $t) * 10);
printf("   Hôte : %s\n", config("database.connections.".config("database.default").".host"));
printf("   Sessions en base : %d lignes\n", DB::table("sessions")->count());
printf("   Cache en base : %d lignes\n", Schema::hasTable("cache") ? DB::table("cache")->count() : -1);
' 2>/dev/null

echo "== 5. Cache navigateur des fichiers JS/CSS (attendu : max-age=31536000, immutable)"
ASSET="$(ls public/build/assets/*.js 2>/dev/null | head -1)"
[ -n "$ASSET" ] && curl -sI --resolve "$HOST:443:127.0.0.1" "$URL/build/assets/$(basename "$ASSET")" \
  | grep -i 'cache-control' | sed 's/^/   /' || echo "   (aucun Cache-Control)"
