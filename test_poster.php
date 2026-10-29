<?php
require __DIR__ . '/backend/vendor/autoload.php';
$app = require_once __DIR__ . '/backend/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$r = Illuminate\Support\Facades\Http::post('https://apis.justwatch.com/graphql', [
    'query' => 'query GetSearchTitles($country: Country!, $searchFilter: TitleFilter!) { popularTitles(country: $country, filter: $searchFilter, first: 1) { edges { node { content(country: $country, language: "es") { title posterUrl } } } } }',
    'variables' => ['country' => 'HN', 'searchFilter' => ['searchQuery' => 'el botin', 'packages' => ['nfx']]]
]);
echo json_encode($r->json(), JSON_PRETTY_PRINT);