<?php

namespace App\Http\Controllers;

use App\Services\Settings\AppSettings;
use Illuminate\Http\Response;

/**
 * ADR-184 — robots.txt suit le réglage du site : masquée aux moteurs de
 * recherche, l'application refuse toute exploration. Servi par Laravel et non
 * par un fichier fixe de `public/`, qui dirait la même chose à tous les sites.
 */
class RobotsTxtController extends Controller
{
    public function __invoke(AppSettings $settings): Response
    {
        return response($settings->robotsTxt(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            // Un changement de réglage se voit en quelques minutes.
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
