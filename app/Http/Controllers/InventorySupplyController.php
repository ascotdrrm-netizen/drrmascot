<?php

namespace App\Http\Controllers;

use App\Models\InventorySupply;
use App\Traits\RendersPdfAssets;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class InventorySupplyController extends Controller
{
    use RendersPdfAssets;

    public function index(): View
    {
        return view('inventory_supplies.index', [
            'supplies' => InventorySupply::orderBy('item_name')->get(),
            'routePrefix' => $this->routePrefix(),
            'trashedCount' => InventorySupply::onlyTrashed()->count(),
        ]);
    }

    public function downloadReport(): Response
    {
        $supplies = InventorySupply::orderBy('category')->orderBy('item_name')->get();

        $pdf = Pdf::loadView('inventory_supplies.report', [
            'supplies' => $supplies,
            'totalCount' => $supplies->count(),
            'lowStockCount' => $supplies->filter(fn ($supply) => $supply->isBelowReorderLevel())->count(),
            'ascotLogo' => $this->logoDataUri('ascot-logo.png'),
            'drrmLogo' => $this->logoDataUri('drrm-logo.png'),
        ])->setPaper('a4', 'portrait');

        $filename = sprintf('Inventory-Stock-Report-%s.pdf', now()->format('Y-m-d'));

        return $pdf->download($filename);
    }

    public function trashed(): View
    {
        return view('inventory_supplies.trash', [
            'supplies' => InventorySupply::onlyTrashed()->orderByDesc('deleted_at')->get(),
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function restore(int $inventory_supply): RedirectResponse
    {
        InventorySupply::onlyTrashed()->findOrFail($inventory_supply)->restore();

        return redirect()
            ->route('admin.inventory-supplies.trash')
            ->with('status', 'Supply item restored.');
    }

    public function create(): View
    {
        return view('inventory_supplies.create');
    }

    public function store(Request $request): RedirectResponse
    {
        InventorySupply::create($this->validated($request));

        return redirect()
            ->route('admin.inventory-supplies.index')
            ->with('status', 'Supply item created.');
    }

    public function edit(InventorySupply $inventory_supply): View
    {
        return view('inventory_supplies.edit', ['supply' => $inventory_supply]);
    }

    public function update(Request $request, InventorySupply $inventory_supply): RedirectResponse
    {
        $inventory_supply->update($this->validated($request));

        return redirect()
            ->route('admin.inventory-supplies.index')
            ->with('status', 'Supply item updated.');
    }

    public function destroy(InventorySupply $inventory_supply): RedirectResponse
    {
        $inventory_supply->delete();

        return redirect()
            ->route('admin.inventory-supplies.index')
            ->with('status', 'Supply item deleted.');
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'item_name' => ['required', 'string', 'max:100'],
            'category' => ['required', 'in:First Aid,TLS Kits,Food,Other'],
            'quantity_available' => ['required', 'integer', 'min:0'],
            'reorder_level' => ['required', 'integer', 'min:0'],
        ]);
    }

    protected function routePrefix(): string
    {
        return Auth::user()->role === 'DRRMO Admin' ? 'admin' : 'coordinator';
    }
}
