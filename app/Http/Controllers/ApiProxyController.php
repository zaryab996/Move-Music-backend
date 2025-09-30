<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ApiProxyController extends Controller
{
    private string $baseUrl;
    private string $apiKey;
    private string $referer;

    public function __construct()
    {
        $this->baseUrl = env('MUSIC_API_URL');     // directly from .env
        $this->apiKey  = env('MUSIC_API_KEY');     // directly from .env
        $this->referer = env('MUSIC_API_REFERER'); // directly from .env
    }

    public function login(Request $request)
    {
        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'Referer'   => $this->referer,
        ])->post("{$this->baseUrl}/auth/obtain-token", $request->only(['username', 'password']));

        return response()->json($response->json(), $response->status());
    }

    public function refreshToken(Request $request)
    {
        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'Referer'   => $this->referer,
        ])->post("{$this->baseUrl}/auth/refresh-token", [
            'refresh' => $request->input('refresh'),
        ]);

        return response()->json($response->json(), $response->status());
    }

    public function releases(Request $request)
    {
        $accessToken = $request->bearerToken();
        $response = Http::withHeaders([
            'x-api-key'     => $this->apiKey,
            'Referer'       => $this->referer,
            'Authorization' => 'Bearer ' . $accessToken,

        ])->get("{$this->baseUrl}/releases", [
            'search'   => $request->query('search'),
            'page'     => $request->query('page'),
            'page_size' => $request->query('per_page'),
        ]);
        return $response->json();
    }

    public function artisits(Request $request)
    {
        $accessToken = $request->bearerToken(); 
        $response = Http::withHeaders([
            'x-api-key'     => $this->apiKey,
            'Referer'       => $this->referer,
            'Authorization' => 'Bearer ' . $accessToken,

        ])->get("{$this->baseUrl}/artists", [
            'search'   => $request->query('search'),
            'page'     => $request->query('page'),
            'page_size' => $request->query('per_page'),
        ]);
        return $response->json();
    }

      public function view_release(Request $request, $id)
    {
        $accessToken = $request->bearerToken();
        $response = Http::withHeaders([
            'x-api-key'     => $this->apiKey,
            'Referer'       => $this->referer,
            'Authorization' => 'Bearer ' . $accessToken,
        ])->get("{$this->baseUrl}/releases/{$id}");
        return $response->json();
    }

    public function view_artist(Request $request, $id)
    {
        $accessToken = $request->bearerToken();
        $response = Http::withHeaders([
            'x-api-key'     => $this->apiKey,
            'Referer'       => $this->referer,
            'Authorization' => 'Bearer ' . $accessToken,
        ])->get("{$this->baseUrl}/artists/{$id}");

        if ($response->status() === 401) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        $artistData = $response->json();
        $tracks = [];
        if (!empty($artistData['spotify_identifier'])) {
            $spotify_id   = $artistData['spotify_identifier'];
            $spotifyToken = $this->getSpotifyToken();
            $tracksResponse = Http::withToken($spotifyToken)
                ->get("https://api.spotify.com/v1/artists/{$spotify_id}/top-tracks", [
                    'market' => 'US',
                ]);
            $tracks = $tracksResponse->json('tracks') ?? [];
        }
        return response()->json([
            'artist' => $artistData,
            'tracks' => $tracks,
        ]);
    }

    public function view_track(Request $request, $id)
    {
        $accessToken = $request->bearerToken();

        $response = Http::withHeaders([
            'x-api-key'     => $this->apiKey,
            'Referer'       => $this->referer,
            'Authorization' => 'Bearer ' . $accessToken,
        ])->get("{$this->baseUrl}/tracks/{$id}");


        return $response->json();
    }

    private function getSpotifyToken()
    {
        return Cache::remember('spotify_access_token', 3500, function () {
            $res = Http::asForm()->post('https://accounts.spotify.com/api/token', [
                'grant_type'    => 'client_credentials',
                'client_id'     => env('SPOTIFY_CLIENT_ID'),
                'client_secret' => env('SPOTIFY_CLIENT_SECRET'),
            ]);

            if ($res->failed()) {
                throw new \Exception('Failed to fetch Spotify token');
            }

            return $res->json()['access_token'];
        });
    }
}
