<div class="card shadow-sm border-0">
    <div class="card-body table-responsive">
        <table class="table table-bordered align-middle mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Buyer Name</th>
                    <th>Season Name</th>
                    <th>Style Name</th>
                    <th>Contract Number</th>
                    <th>Contract Shipment Date</th>
                    <th>Status</th>
                    <th width="200">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($files as $file)
                    @php
                        $summary = [
                            'Buyer Name' => '',
                            'Season Name' => '',
                            'Style Name' => '',
                            'Contract Number' => '',
                            'Contract Shipment Date' => '',
                        ];

                        $firstRow = $file->rows->sortBy('row_number')->first();

                        if ($firstRow) {
                            foreach ($firstRow->cells as $cell) {
                                $headerName = optional($cell->header)->header_name;
                                if (array_key_exists($headerName, $summary) && $summary[$headerName] === '') {
                                    $summary[$headerName] = $cell->value ?? '';
                                }
                            }
                        }

                        $status = strtolower($file->status ?? 'pending');

                        $statusClass = match($status) {
                            'pending' => 'warning',
                            'processing' => 'info',
                            'completed' => 'success',
                            'locked' => 'secondary',
                            default => 'secondary',
                        };

                        $actionLabel = match($status) {
                            'pending' => 'Open',
                            'processing' => 'Edit',
                            'completed' => 'Done',
                            'locked' => 'Locked',
                            default => 'Open',
                        };

                        $canDelete = auth()->user()->hasRole('admin') || auth()->user()->hasRole('merchant');
                    @endphp

                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $summary['Buyer Name'] ?: '-' }}</td>
                        <td>{{ $summary['Season Name'] ?: '-' }}</td>
                        <td>{{ $summary['Style Name'] ?: '-' }}</td>
                        <td>{{ $summary['Contract Number'] ?: '-' }}</td>
                        <td>{{ $summary['Contract Shipment Date'] ?: '-' }}</td>
                        <td>
                            <span class="badge bg-{{ $statusClass }}">
                                {{ ucfirst($status) }}
                            </span>
                        </td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                @if($status === 'locked')
                                    <span class="badge bg-secondary">Locked</span>
                                @else
                                    <a href="{{ route('uploaded-files.show', $file->id) }}" class="btn btn-sm btn-primary">
                                        {{ $actionLabel }}
                                    </a>
                                @endif

                                @if($canDelete)
                                    <form action="{{ route('uploaded-files.destroy', $file->id) }}"
                                          method="POST"
                                          onsubmit="return confirm('Are you sure you want to delete this file?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger">
                                            Delete
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center">No uploaded files found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>