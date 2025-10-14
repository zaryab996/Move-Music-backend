<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

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
        $search      = $request->query('search');
        $page        = $request->query('page', 1);
        $perPage     = $request->query('per_page', 10);
        $ordering    = $request->query('ordering'); // only column name — no direction
        $cacheKey    = "releases:search:{$search}:page:{$page}:per:{$perPage}:order:{$ordering}";

        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use (
                $accessToken,
                $search,
                $page,
                $perPage,
                $ordering
            ) {
                $params = [
                    'search'    => $search,
                    'page'      => $page,
                    'page_size' => $perPage,
                ];

                if ($ordering) {
                    $params['ordering'] = $ordering; // send only column name
                }

                $response = Http::withHeaders([
                    'x-api-key'     => $this->apiKey,
                    'Referer'       => $this->referer,
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->get("{$this->baseUrl}/releases", $params);

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
        $ordering = $request->query('ordering');

        // Generate unique cache key
        $cacheKey = "artists:search:{$search}:page:{$page}:per:{$perPage}";
        if ($ordering) {
            $cacheKey .= ":order:{$ordering}";
        }

        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($accessToken, $search, $page, $perPage, $ordering) {
                // Build query parameters
                $query = [
                    'search'    => $search,
                    'page'      => $page,
                    'page_size' => $perPage,
                ];

                if ($ordering) {
                    $query['ordering'] = $ordering;
                }

                $response = Http::withHeaders([
                    'x-api-key'     => $this->apiKey,
                    'Referer'       => $this->referer,
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->get("{$this->baseUrl}/artists", $query);

                if ($response->successful()) {
                    return $response->json();
                }

                throw new \Exception("API error: " . $response->body(), $response->status());
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
        $ordering = $request->query('ordering');

        // Build cache key including ordering if present
        $cacheKey = "labels:search:{$search}:page:{$page}:per:{$perPage}";
        if ($ordering) {
            $cacheKey .= ":order:{$ordering}";
        }

        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($accessToken, $search, $page, $perPage, $ordering) {
                // Prepare query params dynamically
                $query = [
                    'search'    => $search,
                    'page'      => $page,
                    'page_size' => $perPage,
                ];

                if ($ordering) {
                    $query['ordering'] = $ordering; // ✅ Add ordering if available
                }

                $response = Http::withHeaders([
                    'x-api-key'     => $this->apiKey,
                    'Referer'       => $this->referer,
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->get("{$this->baseUrl}/labels", $query);

                if ($response->successful()) {
                    return $response->json();
                }

                throw new \Exception("API error: " . $response->status(), $response->status());
            });

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to fetch labels',
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
        $ordering = $request->query('ordering');

        // Combine release name into search if available
        if (!empty($release) && empty($search)) {
            $search = $release;
        }

        // Build cache key including ordering if provided
        $cacheKey = "delivered:search:{$search}:page:{$page}:per:{$perPage}";
        if ($ordering) {
            $cacheKey .= ":order:{$ordering}";
        }

        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($accessToken, $search, $page, $perPage, $ordering) {
                $queryParams = [
                    'page'      => $page,
                    'page_size' => $perPage,
                ];

                if (!empty($search)) {
                    $queryParams['search'] = $search;
                }

                if (!empty($ordering)) {
                    $queryParams['ordering'] = $ordering; // ✅ Add ordering
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
        $ordering = $request->query('ordering'); // new: optional ordering param

        // include ordering in cache key only if present
        $cacheKey = "statements:search:{$search}:page:{$page}:per:{$perPage}";
        if (!empty($ordering)) {
            $cacheKey .= ":ordering:{$ordering}";
        }

        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($accessToken, $search, $page, $perPage, $ordering) {
                $queryParams = [
                    'page'      => $page,
                    'page_size' => $perPage,
                ];

                if (!empty($search)) {
                    $queryParams['search'] = $search;
                }

                if (!empty($ordering)) {
                    $queryParams['ordering'] = $ordering; // add ordering only if provided
                }

                $response = Http::withHeaders([
                    'x-api-key'     => $this->apiKey,
                    'Referer'       => $this->referer,
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->get("{$this->baseUrl}/statements", $queryParams);

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


    public function profile(Request $request, $id)
    {
        $accessToken = $request->bearerToken();
        $cacheKey = "profile:{$id}";
        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($accessToken, $id) {
                $response = Http::withHeaders([
                    'x-api-key'     => $this->apiKey,
                    'Referer'       => $this->referer,
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->get("{$this->baseUrl}/users/{$id}");
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

    public function invoices(Request $request)
    {
        $accessToken = $request->bearerToken();
        $search   = $request->query('search');
        $page     = $request->query('page', 1);
        $perPage  = $request->query('per_page', 10);
        $ordering = $request->query('ordering'); // ✅ optional ordering parameter

        // ✅ Include ordering in cache key only if provided
        $cacheKey = "invoices:search:{$search}:page:{$page}:per:{$perPage}";
        if (!empty($ordering)) {
            $cacheKey .= ":ordering:{$ordering}";
        }

        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($accessToken, $search, $page, $perPage, $ordering) {
                $queryParams = [
                    'page'      => $page,
                    'page_size' => $perPage,
                ];

                if (!empty($search)) {
                    $queryParams['search'] = $search;
                }

                if (!empty($ordering)) {
                    $queryParams['ordering'] = $ordering; // ✅ only add if exists
                }

                $response = Http::withHeaders([
                    'x-api-key'     => $this->apiKey,
                    'Referer'       => $this->referer,
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->get("{$this->baseUrl}/invoices", $queryParams);

                if ($response->successful()) {
                    return $response->json();
                }

                throw new \Exception("API error: " . $response->status(), $response->status());
            });

            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to fetch invoices',
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }


    public function invoice_statements(Request $request)
    {
        $accessToken = $request->bearerToken();
        $search   = $request->query('search');
        $page     = $request->query('page', 1);
        $perPage  = $request->query('per_page', 10);

        $cacheKey = "invoicestatements:search:{$search}:page:{$page}:per:{$perPage}";

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
                    'invoice_generated' => "false"
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


    public function generate_invoice(Request $request)
    {
        $accessToken = $request->bearerToken();

        try {
            $response = Http::withHeaders([
                'x-api-key'     => $this->apiKey,
                'Referer'       => $this->referer,
                'Authorization' => 'Bearer ' . $accessToken,
            ])->post("{$this->baseUrl}/invoices/generate-invoice");



            if ($response->successful()) {
                return response()->json($response->json(), 200);
            }

            return response()->json([
                'error' => 'API Error',
                'message' => $response->body(),
            ], $response->status());
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to generate invoice',
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    public function trends(Request $request)
    {
        $accessToken = $request->bearerToken();
        $filters = [
            'period'   => $request->query('period', 1),
            'store'    => $request->query('store', ''),
            'label'    => $request->query('label', ''),
            'release'  => $request->query('release', ''),
            'track'    => $request->query('track', ''),
            'search'   => $request->query('search', ''),
        ];
        $cacheKey = 'trends:' . md5(json_encode($filters));
        try {
            $data = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($accessToken, $filters) {
                $response = Http::withHeaders([
                    'x-api-key'     => $this->apiKey,
                    'Referer'       => $this->referer,
                    'Authorization' => 'Bearer ' . $accessToken,
                ])->get("{$this->baseUrl}/trends", $filters);

                if ($response->successful()) {
                    return $response->json();
                }
                throw new \Exception(
                    "API Error: {$response->status()} - " . $response->body(),
                    $response->status()
                );
            });
            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json([
                'error'   => 'Failed to fetch trends',
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
