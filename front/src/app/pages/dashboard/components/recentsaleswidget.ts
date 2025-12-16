import { Component, OnInit, signal } from '@angular/core';
import { RippleModule } from 'primeng/ripple';
import { TableModule } from 'primeng/table';
import { ButtonModule } from 'primeng/button';
import { CommonModule } from '@angular/common';
import { HttpClient } from '@angular/common/http';
import { IGeneralResponse } from '@/pages/auth/login';
import { catchError, delay, finalize, map } from 'rxjs/operators';
import { of } from 'rxjs';
import { Skeleton } from 'primeng/skeleton';
import { Tag } from 'primeng/tag';
import { BASE_URL } from '../../../../constants';

@Component({
  standalone: true,
  selector: 'app-recent-sales-widget',
  imports: [CommonModule, TableModule, ButtonModule, RippleModule, Skeleton, Tag],
  template: ` <div class="card mb-8!">
    <div class="font-semibold text-xl mb-4">Clubs management</div>
    @if (loading()) {
      <p-skeleton
        width="100%"
        height="calc(100vh - 400px)"
        borderRadius="8px"
      ></p-skeleton>
    } @else {
      <p-table
        [value]="clubs"
        [paginator]="true"
        [rows]="10"
        responsiveLayout="scroll"
      >
        <ng-template #header>
          <tr>
            <th>Club name</th>
            <th pSortableColumn="members">
              Members
              <p-sortIcon field="members"></p-sortIcon>
            </th>
            <th pSortableColumn="events">
              Events
              <p-sortIcon field="events"></p-sortIcon>
            </th>
            <th>Captain</th>
            <th>Vice Captain</th>
            <th>Category</th>
            <th>Status</th>
          </tr>
        </ng-template>
        <ng-template
          #body
          let-club
        >
          <tr>
            <td style="width: 25%; min-width: 6rem;">{{ club.club_name }}</td>
            <td style="width: 15%;">{{ club.members }}</td>
            <td style="width: 15%;">{{ club.events }}</td>
            <td style="width: 25%;">{{ club.captain }}</td>
            <td style="width: 25%;">{{ club.vice_captain }}</td>
            <td style="width: 25%;">{{ club.category }}</td>
            <td style="width: 25%;">
              <p-tag
                [value]="club.status"
                [severity]="club.status === 'Active' ? 'success' : 'danger'"
              ></p-tag>
            </td>
          </tr>
        </ng-template>
      </p-table>
    }
  </div>`,
  providers: [],
})
export class RecentSalesWidget implements OnInit {
  clubs: ClubRecord[] = [];
  loading = signal(true);

  constructor(private httpClient: HttpClient) {}

  ngOnInit() {
    this.httpClient
      .get<IGeneralResponse<{ clubs: ClubRecord[] }>>(
        `http://${BASE_URL}/server/api/reports/clubs-summary`,
      )
      .pipe(
        delay(500),
        map(({ data }) => {
          if (!data) return <ClubRecord[]>[];
          return data.clubs;
        }),
        catchError(() => of(<ClubRecord[]>[])),
        finalize(() => this.loading.set(false)),
      )
      .subscribe((data) => {
        this.clubs = data;
      });
  }
}

interface ClubRecord {
  club_id: number;
  club_name: string;
  category: string;
  status: string;
  members: number;
  events: number;
  captain: string | null;
  captain_email: string | null;
  captain_phone: string | null;
  vice_captain: string | null;
  created_at: string;
  updated_at: string;
}
