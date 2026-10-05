<?php

namespace Modules\Specifications\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Specifications\Http\Requests\StoreSpecificationValueRequest;
use Modules\Specifications\Http\Requests\UpdateSpecificationValueRequest;
use Modules\Specifications\Models\SpecificationValue;

class SpecificationValueController extends Controller
{
    
    public function store(StoreSpecificationValueRequest $request)
    {
        $value = SpecificationValue::create($request->validated());
        return response()->json(['message' => 'Specification value created successfully', 'data' => $value]);
    }

    public function update(UpdateSpecificationValueRequest $request, $id)
    {
        $value = SpecificationValue::findOrFail($id);
        $value->update($request->validated());
        return response()->json(['message' => 'Specification value updated successfully', 'data' => $value]);
    }

    public function destroy($id)
    {
        SpecificationValue::destroy($id);
        return response()->json(['message' => 'Specification value deleted successfully']);
    }
}
