{{-- An advisory's status, by its display label: "In effect" (stored as Active), "Expired" (stored as
     Active, but past its valid-until time and off the dashboards), "Passed" or "Cancelled". --}}
<x-badge :value="$advisory->status_label" :tip="config('glossary.advisory_status')[$advisory->status_label] ?? ''"
         :map="['Passed' => 'bg-gray-50 text-gray-700', 'Cancelled' => 'bg-gray-50 text-gray-700']" />
