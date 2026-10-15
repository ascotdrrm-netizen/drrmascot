<?php

namespace App\Http\Controllers;

use App\Exports\RequisitionsExport;
use App\Http\Controllers\Concerns\HandlesCampusScoping;
use App\Models\ActivityLog;
use App\Models\Campus;
use App\Models\InventorySupply;
use App\Models\Requisition;
use App\Traits\RendersPdfAssets;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RequisitionController extends Controller
{
    use HandlesCampusScoping, RendersPdfAssets;

    public function index(Request $request): View
    {
        $user = Auth::user();
        $trashedQuery = Requisition::onlyTrashed();

        if ($user->role === 'Coordinator') {
            $trashedQuery->where('campus_id', $user->campus_id);
        } elseif ($request->filled('campus_id')) {
            $trashedQuery->where('campus_id', $request->input('campus_id'));
        }

        return view('requisitions.index', [
            'requisitions' => $this->filteredQuery($request)->orderByDesc('date_requested')->orderByDesc('requisition_id')->get(),
            'campuses' => $user->role === 'DRRMO Admin' ? Campus::orderBy('campus_name')->get() : null,
            'selectedCampusId' => $request->input('campus_id'),
            'selectedStatus' => $request->input('status'),
            'routePrefix' => $this->routePrefix(),
            'trashedCount' => $trashedQuery->count(),
        ]);
    }

    protected function filteredQuery(Request $request)
    {
        $user = Auth::user();
        $query = Requisition::with(['campus', 'items.supply']);

        if ($user->role === 'Coordinator') {
            $query->where('campus_id', $user->campus_id);
        } elseif ($request->filled('campus_id')) {
            $query->where('campus_id', $request->input('campus_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $query;
    }

    public function exportExcel(Request $request)
    {
        $requisitions = $this->filteredQuery($request)
            ->with('submittedBy')
            ->orderByDesc('date_requested')
            ->orderByDesc('requisition_id')
            ->get();

        $user = Auth::user();
        $campusLabel = 'AllCampuses';

        if ($user->role === 'Coordinator') {
            $campusLabel = Str::slug($user->campus->campus_name ?? 'campus', '');
        } elseif ($request->filled('campus_id')) {
            $campusLabel = Str::slug(Campus::find($request->input('campus_id'))->campus_name ?? 'campus', '');
        }

        $filename = sprintf('Requisitions-Export-%s-%s.xlsx', $campusLabel, now()->format('Y-m-d'));

        return Excel::download(new RequisitionsExport($requisitions), $filename);
    }

    public function trashed(Request $request): View
    {
        $user = Auth::user();
        $query = Requisition::onlyTrashed()->with(['campus', 'items.supply']);

        if ($user->role === 'Coordinator') {
            $query->where('campus_id', $user->campus_id);
        } elseif ($request->filled('campus_id')) {
            $query->where('campus_id', $request->input('campus_id'));
        }

        return view('requisitions.trash', [
            'requisitions' => $query->orderByDesc('deleted_at')->get(),
            'campuses' => $user->role === 'DRRMO Admin' ? Campus::orderBy('campus_name')->get() : null,
            'selectedCampusId' => $request->input('campus_id'),
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function restore(int $requisition): RedirectResponse
    {
        $requisition = Requisition::onlyTrashed()->findOrFail($requisition);
        $this->authorizeCampusAccess($requisition);
        $requisition->restore();

        return redirect()
            ->route("{$this->routePrefix()}.requisitions.trash")
            ->with('status', 'Requisition restored.');
    }

    protected const SUPPLY_CATEGORIES = ['First Aid', 'TLS Kits', 'Food', 'Other'];

    public function create(): View
    {
        $grouped = InventorySupply::orderBy('item_name')->get()->groupBy('category');

        $suppliesByCategory = collect(self::SUPPLY_CATEGORIES)
            ->mapWithKeys(fn ($category) => [$category => $grouped->get($category, collect())])
            ->filter(fn ($items) => $items->isNotEmpty());

        return view('requisitions.create', [
            'suppliesByCategory' => $suppliesByCategory,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'catalog_items' => ['nullable', 'array'],
            'catalog_items.*' => ['integer', 'exists:inventory_supplies,supply_id'],
            'catalog_quantities' => ['nullable', 'array'],
            'catalog_quantities.*' => ['nullable', 'integer', 'min:1'],
            'custom_items' => ['nullable', 'array'],
            'custom_items.*.name' => ['nullable', 'string', 'max:150'],
            'custom_items.*.quantity' => ['nullable', 'integer', 'min:1'],
            'letter' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
        ]);

        $lineItems = $this->resolveLineItems($validated);

        $requisition = Requisition::create([
            'campus_id' => $user->campus_id,
            'status' => 'Pending',
            'date_requested' => now()->toDateString(),
            'submitted_by' => Auth::id(),
        ]);

        foreach ($lineItems as $lineItem) {
            $requisition->items()->create($lineItem);
        }

        if ($request->hasFile('letter')) {
            $requisition->update([
                'letter_path' => $this->storeLetter($request->file('letter'), $requisition),
            ]);
        }

        return redirect()
            ->route('coordinator.requisitions.show', $requisition)
            ->with('status', 'Requisition submitted.');
    }

    /**
     * Turn the raw validated catalog/custom item input into a flat list of
     * requisition_items rows to create, throwing a clear validation error for
     * any inconsistency (missing quantity, half-filled custom row, nothing selected).
     */
    protected function resolveLineItems(array $validated): array
    {
        $lineItems = [];

        foreach ($validated['catalog_items'] ?? [] as $supplyId) {
            $quantity = $validated['catalog_quantities'][$supplyId] ?? null;

            if ($quantity === null) {
                throw ValidationException::withMessages([
                    'catalog_quantities' => 'Enter a quantity for every selected catalog item.',
                ]);
            }

            $lineItems[] = ['supply_id' => (int) $supplyId, 'quantity_requested' => (int) $quantity];
        }

        foreach ($validated['custom_items'] ?? [] as $row) {
            $name = trim($row['name'] ?? '');
            $quantity = $row['quantity'] ?? null;

            if ($name === '' && $quantity === null) {
                continue;
            }

            if ($name === '' || $quantity === null) {
                throw ValidationException::withMessages([
                    'custom_items' => 'Each custom item needs both a name and a quantity.',
                ]);
            }

            $lineItems[] = ['custom_item_name' => $name, 'quantity_requested' => (int) $quantity];
        }

        if (empty($lineItems)) {
            throw ValidationException::withMessages([
                'items' => 'Select at least one item (catalog or custom) before submitting.',
            ]);
        }

        return $lineItems;
    }

    public function show(Requisition $requisition): View
    {
        $this->authorizeCampusAccess($requisition);

        $requisition->load(['campus', 'items.supply', 'submittedBy']);

        $canAccessLetter = $this->canAccessLetter($requisition);

        return view('requisitions.show', [
            'requisition' => $requisition,
            'routePrefix' => $this->routePrefix(),
            'canAccessLetter' => $canAccessLetter,
            'canEditLetter' => $canAccessLetter && $requisition->status === 'Pending',
        ]);
    }

    public function downloadReport(Requisition $requisition): Response
    {
        $this->authorizeCampusAccess($requisition);

        if (! $this->canAccessLetter($requisition)) {
            abort(403, 'Only the DRRMO Admin and the Coordinator who submitted this requisition can generate its report.');
        }

        $requisition->load(['campus', 'items.supply', 'submittedBy']);

        $statusTimeline = in_array($requisition->status, ['Approved', 'Dispatched'], true)
            ? ActivityLog::with('actor')
                ->where('subject_type', 'Requisition')
                ->where('subject_id', $requisition->requisition_id)
                ->where('action', 'updated')
                ->orderBy('created_at')
                ->get()
            : collect();

        $pdf = Pdf::loadView('requisitions.report', [
            'requisition' => $requisition,
            'statusTimeline' => $statusTimeline,
            'ascotLogo' => $this->logoDataUri('ascot-logo.png'),
            'drrmLogo' => $this->logoDataUri('drrm-logo.png'),
        ])->setPaper('a4', 'portrait');

        $filename = sprintf(
            'Requisition-Slip-REQ-%d-%s.pdf',
            $requisition->requisition_id,
            $requisition->date_requested->format('Y-m-d')
        );

        return $pdf->download($filename);
    }

    public function downloadLetter(Requisition $requisition): StreamedResponse
    {
        $this->authorizeCampusAccess($requisition);

        if (! $this->canAccessLetter($requisition)) {
            abort(403, 'Only the DRRMO Admin and the Coordinator who submitted this requisition can view its letter.');
        }

        if (! $requisition->hasLetter()) {
            abort(404, 'No letter attached to this requisition.');
        }

        $extension = pathinfo($requisition->letter_path, PATHINFO_EXTENSION);
        $downloadName = "requisition-{$requisition->requisition_id}-letter.{$extension}";

        return Storage::disk('local')->download($requisition->letter_path, $downloadName);
    }

    public function editLetter(Requisition $requisition): View
    {
        $this->authorizeCampusAccess($requisition);
        $this->authorizeLetterEdit($requisition);

        return view('requisitions.edit-letter', [
            'requisition' => $requisition,
            'routePrefix' => $this->routePrefix(),
        ]);
    }

    public function updateLetter(Request $request, Requisition $requisition): RedirectResponse
    {
        $this->authorizeCampusAccess($requisition);
        $this->authorizeLetterEdit($requisition);

        $data = $request->validate([
            'letter' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:10240'],
        ]);

        if ($requisition->letter_path) {
            Storage::disk('local')->delete($requisition->letter_path);
        }

        $requisition->update([
            'letter_path' => $this->storeLetter($request->file('letter'), $requisition),
        ]);

        return redirect()
            ->route("{$this->routePrefix()}.requisitions.show", $requisition)
            ->with('status', 'Letter updated.');
    }

    protected function canAccessLetter(Requisition $requisition): bool
    {
        return Auth::user()->role === 'DRRMO Admin' || Auth::id() === $requisition->submitted_by;
    }

    protected function authorizeLetterEdit(Requisition $requisition): void
    {
        if (! $this->canAccessLetter($requisition)) {
            abort(403, 'Only the DRRMO Admin and the Coordinator who submitted this requisition can manage its letter.');
        }

        if ($requisition->status !== 'Pending') {
            abort(403, 'The letter can only be changed while the requisition is Pending.');
        }
    }

    protected function storeLetter(UploadedFile $letter, Requisition $requisition): string
    {
        $campusSlug = Str::slug($requisition->campus->campus_name ?? '');

        if ($campusSlug === '') {
            $campusSlug = 'campus';
        }

        $filename = sprintf(
            '%s-%s-%d-%s.%s',
            $campusSlug,
            $requisition->date_requested->format('Y-m-d'),
            $requisition->requisition_id,
            Str::random(6),
            $letter->getClientOriginalExtension()
        );

        return $letter->storeAs('requisition-letters', $filename, 'local');
    }

    public function destroy(Requisition $requisition): RedirectResponse
    {
        $this->authorizeCampusAccess($requisition);

        if ($requisition->status !== 'Pending') {
            abort(403, 'Only pending requisitions can be cancelled.');
        }

        if ($requisition->letter_path) {
            Storage::disk('local')->delete($requisition->letter_path);
        }

        $requisition->delete();

        return redirect()
            ->route('coordinator.requisitions.index')
            ->with('status', 'Requisition cancelled.');
    }

    public function updateStatus(Request $request, Requisition $requisition): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:Approved,Denied,Dispatched'],
        ]);

        $newStatus = $data['status'];
        $current = $requisition->status;

        $validTransitions = [
            'Pending' => ['Approved', 'Denied'],
            'Approved' => ['Dispatched', 'Denied'],
        ];

        if (! in_array($newStatus, $validTransitions[$current] ?? [], true)) {
            return back()->withErrors([
                'status' => "Cannot change status from {$current} to {$newStatus}.",
            ]);
        }

        if ($current === 'Pending' && $newStatus === 'Approved') {
            DB::transaction(function () use ($requisition, $newStatus) {
                $catalogItems = $requisition->items()->whereNotNull('supply_id')->get();

                // Custom-item lines (no supply_id) have nothing to check stock against or
                // decrement — approval just means "proceed with manual sourcing outside inventory."
                if ($catalogItems->isEmpty()) {
                    $requisition->update(['status' => $newStatus]);

                    return;
                }

                $supplies = InventorySupply::whereIn('supply_id', $catalogItems->pluck('supply_id'))
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('supply_id');

                // Pass 1: verify every catalog line item has enough stock before touching anything.
                // All-or-nothing — a single short item must reject the whole approval, not just itself.
                foreach ($catalogItems as $item) {
                    $supply = $supplies->get($item->supply_id);

                    if ($supply->quantity_available < $item->quantity_requested) {
                        throw ValidationException::withMessages([
                            'status' => "Insufficient stock: {$supply->item_name} (requested {$item->quantity_requested}, only {$supply->quantity_available} available).",
                        ]);
                    }
                }

                // Pass 2: only reached if every line item passed the check above.
                foreach ($catalogItems as $item) {
                    $supplies->get($item->supply_id)->decrement('quantity_available', $item->quantity_requested);
                }

                $requisition->update(['status' => $newStatus]);
            });
        } else {
            $requisition->update(['status' => $newStatus]);
        }

        return redirect()
            ->route('admin.requisitions.show', $requisition)
            ->with('status', "Requisition marked {$newStatus}.");
    }
}
