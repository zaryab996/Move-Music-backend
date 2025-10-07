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
        $this->baseUrl = env('MUSIC_API_URL');
        $this->apiKey  = env('MUSIC_API_KEY');
        $this->referer = env('MUSIC_API_REFERER');
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
        $search   = $request->query('search');
        $page     = $request->query('page', 1);
        $perPage  = $request->query('per_page', 10);

        $cacheKey = "releases:search:{$search}:page:{$page}:per:{$perPage}";

        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($accessToken, $search, $page, $perPage) {
                $response = Http::withHeaders([
                    'x-api-key'     => $this->apiKey,
                    'Referer'       => $this->referer,
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->get("{$this->baseUrl}/releases", [
                    'search'    => $search,
                    'page'      => $page,
                    'page_size' => $perPage,
                ]);

                if ($response->successful()) {
                    return $response->json();
                }

                throw new \Exception("API error: " . $response->status(), $response->status());
            });

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to fetch releases',
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    public function artists(Request $request)
    {
        $accessToken = $request->bearerToken();
        $search   = $request->query('search');
        $page     = $request->query('page', 1);
        $perPage  = $request->query('per_page', 10);

        $cacheKey = "artists:search:{$search}:page:{$page}:per:{$perPage}";

        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($accessToken, $search, $page, $perPage) {
                $response = Http::withHeaders([
                    'x-api-key'     => $this->apiKey,
                    'Referer'       => $this->referer,
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->get("{$this->baseUrl}/artists", [
                    'search'    => $search,
                    'page'      => $page,
                    'page_size' => $perPage,
                ]);

                if ($response->successful()) {
                    return $response->json();
                }

                throw new \Exception("API error: " . $response->status(), $response->status());
            });

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to fetch artists',
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    public function view_release(Request $request, $id)
    {
        $accessToken = $request->bearerToken();
        $cacheKey = "release:{$id}";

        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($accessToken, $id) {
                $response = Http::withHeaders([
                    'x-api-key'     => $this->apiKey,
                    'Referer'       => $this->referer,
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->get("{$this->baseUrl}/releases/{$id}");

                if ($response->successful()) {
                    return $response->json();
                }

                throw new \Exception("API error: " . $response->status(), $response->status());
            });

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to fetch release',
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    public function view_artist(Request $request, $id)
    {
        $accessToken = $request->bearerToken();
        $cacheKey = "artist:{$id}";

        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($accessToken, $id) {
                $response = Http::withHeaders([
                    'x-api-key'     => $this->apiKey,
                    'Referer'       => $this->referer,
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->get("{$this->baseUrl}/artists/{$id}");

                if (!$response->successful()) {
                    throw new \Exception("API error: " . $response->status(), $response->status());
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

                return [
                    'artist' => $artistData,
                    'tracks' => $tracks,
                ];
            });

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to fetch artist',
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    public function view_track(Request $request, $id)
    {
        $accessToken = $request->bearerToken();
        $cacheKey = "track:{$id}";

        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($accessToken, $id) {
                $response = Http::withHeaders([
                    'x-api-key'     => $this->apiKey,
                    'Referer'       => $this->referer,
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->get("{$this->baseUrl}/tracks/{$id}");

                if ($response->successful()) {
                    return $response->json();
                }

                throw new \Exception("API error: " . $response->status(), $response->status());
            });

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to fetch track',
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    public function labels(Request $request)
    {
        $accessToken = $request->bearerToken();
        $search   = $request->query('search');
        $page     = $request->query('page', 1);
        $perPage  = $request->query('per_page', 10);

        $cacheKey = "labels:search:{$search}:page:{$page}:per:{$perPage}";

        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($accessToken, $search, $page, $perPage) {
                $response = Http::withHeaders([
                    'x-api-key'     => $this->apiKey,
                    'Referer'       => $this->referer,
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->get("{$this->baseUrl}/labels", [
                    'search'    => $search,
                    'page'      => $page,
                    'page_size' => $perPage,
                ]);


                if ($response->successful()) {
                    return $response->json();
                }

                throw new \Exception("API error: " . $response->status(), $response->status());
            });

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to fetch releases',
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    public function delivered_list(Request $request)
{
    $accessToken = $request->bearerToken();
    $search   = $request->query('search');
    $release  = $request->query('release'); // optional (from frontend route param)
   
    $page     = $request->query('page', 1);
    $perPage  = $request->query('per_page', 10);

    // Combine release name into search if available
    if (!empty($release) && empty($search)) {
        $search = $release;
    }

    $cacheKey = "delivered:search:{$search}:page:{$page}:per:{$perPage}";

    try {
        $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($accessToken, $search, $page, $perPage) {
            $queryParams = [
                'page'      => $page,
                'page_size' => $perPage,
            ];

            if (!empty($search)) {
                $queryParams['search'] = $search;
            }

            $response = Http::withHeaders([
                'x-api-key'     => $this->apiKey,
                'Referer'       => $this->referer,
                'Authorization' => 'Bearer ' . $accessToken,
            ])->get("{$this->baseUrl}/ddex-delivery-confirmations", $queryParams);

            if ($response->successful()) {
                return $response->json();
            }

            throw new \Exception("API error: " . $response->status(), $response->status());
        });

        return response()->json($data);
    } catch (\Exception $e) {
        return response()->json([
            'error'   => 'Failed to fetch delivered list',
            'message' => $e->getMessage(),
        ], $e->getCode() ?: 500);
    }
}


    public function view_delivered_list(Request $request, $id)
    {
        
        $accessToken = $request->bearerToken();
        $cacheKey = "delivered:{$id}";


        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($accessToken, $id) {
                $response = Http::withHeaders([
                    'x-api-key'     => $this->apiKey,
                    'Referer'       => $this->referer,
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->get("{$this->baseUrl}/ddex-delivery-confirmations/{$id}");


                if ($response->successful()) {
                    return $response->json();
                }

                throw new \Exception("API error: " . $response->status(), $response->status());
            });

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to fetch releases',
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

     public function statements(Request $request)
    {
        $accessToken = $request->bearerToken();
        $search   = $request->query('search');
        $page     = $request->query('page', 1);
        $perPage  = $request->query('per_page', 10);

        $cacheKey = "statements:search:{$search}:page:{$page}:per:{$perPage}";

        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($accessToken, $search, $page, $perPage) {
                $response = Http::withHeaders([
                    'x-api-key'     => $this->apiKey,
                    'Referer'       => $this->referer,
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->get("{$this->baseUrl}/statements", [
                    'search'    => $search,
                    'page'      => $page,
                    'page_size' => $perPage,
                ]);


                if ($response->successful()) {
                    return $response->json();
                }

                throw new \Exception("API error: " . $response->status(), $response->status());
            });

            return response()->json($data);
            
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to fetch releases',
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
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
