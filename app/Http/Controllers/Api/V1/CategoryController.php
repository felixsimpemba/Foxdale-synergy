<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Category::class);

        return CategoryResource::collection(Category::query()->orderBy('name')->orderBy('id')->get());
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        Gate::authorize('create', Category::class);

        return (new CategoryResource(Category::create($request->validated())))->response()->setStatusCode(201);
    }
}
