{{-- App\Filament\Concerns\GuardsWidgetRendering: the widget's own HTML, or a note when it failed. --}}
{{-- One expression, no @if: Livewire marks @if blocks with comments, and the output must start with the root tag. --}}
{!! ($guardedView === null ? null : \App\Support\ModuleGuard::renderView($guardedSection, $guardedView())) ?? view('filament.widgets.guarded-fallback')->render() !!}
