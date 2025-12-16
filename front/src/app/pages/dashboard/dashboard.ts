import { Component } from '@angular/core';
import { StatsWidget } from './components/statswidget';
import { RecentSalesWidget } from './components/recentsaleswidget';

@Component({
  selector: 'app-dashboard',
  imports: [StatsWidget, RecentSalesWidget],
  standalone: true,
  template: `
    <div class="grid grid-cols-12 gap-8">
      <app-stats-widget class="contents" />
    </div>
    <div class="col-span-12 mt-8">
      <app-recent-sales-widget />
    </div>
  `,
})
export class Dashboard {}
