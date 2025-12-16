import { Component, computed, ElementRef, OnInit, signal, ViewChild } from '@angular/core';
import { ConfirmationService, MessageService } from 'primeng/api';
import { InputTextModule } from 'primeng/inputtext';
import { MultiSelectModule } from 'primeng/multiselect';
import { SelectModule } from 'primeng/select';
import { SliderModule } from 'primeng/slider';
import { Table, TableModule } from 'primeng/table';
import { ProgressBarModule } from 'primeng/progressbar';
import { ToggleButtonModule } from 'primeng/togglebutton';
import { ToastModule } from 'primeng/toast';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ButtonModule } from 'primeng/button';
import { RatingModule } from 'primeng/rating';
import { RippleModule } from 'primeng/ripple';
import { InputIconModule } from 'primeng/inputicon';
import { IconFieldModule } from 'primeng/iconfield';
import { TagModule } from 'primeng/tag';
import { HttpClient } from '@angular/common/http';
import { catchError, delay, filter, finalize, map, switchMap } from 'rxjs/operators';
import { IGeneralResponse } from '@/pages/auth/login';
import { BlockUI } from 'primeng/blockui';
import { ProgressSpinner } from 'primeng/progressspinner';
import { of } from 'rxjs';
import { Skeleton } from 'primeng/skeleton';
import { IUser } from '@/pages/events/events';
import { BASE_URL } from '../../../constants';

@Component({
  selector: 'app-payments',
  standalone: true,
  imports: [
    TableModule,
    MultiSelectModule,
    SelectModule,
    InputIconModule,
    TagModule,
    InputTextModule,
    SliderModule,
    ProgressBarModule,
    ToggleButtonModule,
    ToastModule,
    CommonModule,
    FormsModule,
    ButtonModule,
    RatingModule,
    RippleModule,
    IconFieldModule,
    BlockUI,
    ProgressSpinner,
    Skeleton,
  ],
  template: `
    <p-blockUI [blocked]="globalLoading()">
      <div style="display: grid; height: 100%; width: 100%; place-items: center">
        <p-progressSpinner></p-progressSpinner>
      </div>
    </p-blockUI>
    <div class="card">
      <div class="font-semibold text-xl mb-4">Payments</div>
      @if (loading()) {
        <p-skeleton
          width="100%"
          height="calc(100vh - 360px)"
          borderRadius="8px"
        ></p-skeleton>
      } @else {
        <p-table
          #dt1
          [value]="payments()"
          dataKey="id"
          [rows]="10"
          [rowHover]="true"
          [paginator]="true"
          [globalFilterFields]="[
            'user_first_name',
            'user_last_name',
            'club_name',
            'event_title',
            'status',
            'payment_type',
          ]"
          responsiveLayout="scroll"
        >
          <ng-template #caption>
            <div class="flex justify-between items-center flex-column sm:flex-row">
              <button
                pButton
                label="Clear"
                class="p-button-outlined mb-2"
                icon="pi pi-filter-slash"
                (click)="clear(dt1)"
              ></button>
              <p-iconfield
                iconPosition="left"
                class="ml-auto"
              >
                <p-inputicon>
                  <i class="pi pi-search"></i>
                </p-inputicon>
                <input
                  pInputText
                  type="text"
                  (input)="onGlobalFilter(dt1, $event)"
                  placeholder="Search keyword"
                />
              </p-iconfield>
            </div>
          </ng-template>
          <ng-template #header>
            <tr>
              <th style="min-width: 12rem">
                <div class="flex justify-between items-center">
                  Name
                  <p-columnFilter
                    field="user_id"
                    matchMode="in"
                    display="menu"
                    [showMatchModes]="false"
                    [showOperator]="false"
                    [showAddButton]="false"
                  >
                    <ng-template #header>
                      <div class="px-3 pt-3 pb-0">
                        <span class="font-bold">Name</span>
                      </div>
                    </ng-template>
                    <ng-template
                      #filter
                      let-value
                      let-filter="filterCallback"
                    >
                      <p-multiselect
                        [ngModel]="value"
                        [options]="representatives"
                        placeholder="Any"
                        (onChange)="filter($event.value)"
                        optionValue="user_id"
                        optionLabel="user_first_name"
                        styleClass="w-full"
                      >
                        <ng-template
                          let-option
                          #item
                        >
                          <div class="flex items-center gap-2 w-44">
                            <span>{{ option.user_first_name }}</span>
                            <span>{{ option.user_last_name }}</span>
                          </div>
                        </ng-template>
                      </p-multiselect>
                    </ng-template>
                  </p-columnFilter>
                </div>
              </th>
              <th style="min-width: 12rem">
                <div class="flex justify-between items-center">
                  Club name
                  <p-columnFilter
                    type="text"
                    field="club_name"
                    display="menu"
                    placeholder="Search by country"
                  ></p-columnFilter>
                </div>
              </th>
              <th style="min-width: 14rem">
                <div class="flex justify-between items-center">
                  Event
                  <p-columnFilter
                    type="text"
                    field="event_title"
                    display="menu"
                    placeholder="Search by name"
                  ></p-columnFilter>
                </div>
              </th>
              <th style="min-width: 10rem">
                <div class="flex justify-between items-center">
                  Date
                  <p-columnFilter
                    type="date"
                    field="payment_date"
                    display="menu"
                    placeholder="mm/dd/yyyy"
                  ></p-columnFilter>
                </div>
              </th>
              <th style="min-width: 10rem">
                <div class="flex justify-between items-center">
                  Transfer amount
                  <p-columnFilter
                    type="numeric"
                    field="amount"
                    display="menu"
                    currency="USD"
                  ></p-columnFilter>
                </div>
              </th>
              <th style="min-width: 12rem">
                <div class="flex justify-between items-center">
                  Status
                  <p-columnFilter
                    field="status"
                    matchMode="equals"
                    display="menu"
                  >
                    <ng-template
                      #filter
                      let-value
                      let-filter="filterCallback"
                    >
                      <p-select
                        [ngModel]="value"
                        [options]="statuses"
                        (onChange)="filter($event.value)"
                        placeholder="Any"
                        [style]="{ 'min-width': '12rem' }"
                      >
                        <ng-template
                          let-option
                          #item
                        >
                          <span [class]="'customer-badge status-' + option.value">{{
                            option.label
                          }}</span>
                        </ng-template>
                      </p-select>
                    </ng-template>
                  </p-columnFilter>
                </div>
              </th>
              <th style="min-width: 8rem">
                <div class="flex justify-between items-center">
                  Payment type
                  <p-columnFilter
                    field="payment_type"
                    matchMode="equals"
                    display="menu"
                  >
                    <ng-template
                      #filter
                      let-value
                      let-filter="filterCallback"
                    >
                      <p-select
                        [ngModel]="value"
                        [options]="paymentTypes"
                        (onChange)="filter($event.value)"
                        placeholder="Any"
                        [style]="{ 'min-width': '12rem' }"
                      >
                        <ng-template
                          let-option
                          #item
                        >
                          <span [class]="'customer-badge status-' + option.value">{{
                            option.label
                          }}</span>
                        </ng-template>
                      </p-select>
                    </ng-template>
                  </p-columnFilter>
                </div>
              </th>
            </tr>
          </ng-template>
          <ng-template
            #body
            let-payment
          >
            <tr>
              <td>
                <div class="flex items-center gap-2">
                  <span class="image-text"
                    >{{ payment.user_first_name }} {{ payment.user_last_name }}</span
                  >
                </div>
              </td>

              <td>
                <div class="flex items-center gap-2">
                  <span>{{ payment.club_name }}</span>
                </div>
              </td>
              <td>
                {{ payment.event_title }}
              </td>
              <td>
                {{ payment.payment_date | date: 'MM/dd/yyyy' }}
              </td>
              <td>
                {{ payment.amount | currency: payment.currency : 'symbol' }}
              </td>
              <td>
                <p-tag
                  [value]="payment.status.toLowerCase()"
                  [severity]="getSeverity(payment.status.toLowerCase())"
                  styleClass="dark:bg-surface-900!"
                />
              </td>
              <td class="text-center">
                <p-tag
                  [value]="payment.payment_type.toLowerCase()"
                  [severity]="getSeverityPaymentType(payment.payment_type.toLowerCase())"
                  styleClass="dark:bg-surface-900!"
                />
              </td>
            </tr>
          </ng-template>
          <ng-template #emptymessage>
            <tr>
              <td colspan="8">No customers found.</td>
            </tr>
          </ng-template>
          <ng-template #loadingbody>
            <tr>
              <td colspan="8">Loading customers data. Please wait.</td>
            </tr>
          </ng-template>
        </p-table>
      }
    </div>
  `,
  styles: `
    .p-datatable-frozen-tbody {
      font-weight: bold;
    }

    .p-datatable-scrollable .p-frozen-column {
      font-weight: bold;
    }
  `,
  providers: [ConfirmationService, MessageService],
})
export class Payments implements OnInit {
  representatives: Pick<IPayment, 'user_first_name' | 'user_last_name' | 'user_id'>[] = [];

  statuses: { label: string; value: string }[] = [
    { label: 'completed', value: 'completed' },
    { label: 'failed', value: 'failed' },
    { label: 'pending', value: 'pending' },
  ];

  paymentTypes: { label: string; value: string }[] = [
    { label: 'event_fee', value: 'event_fee' },
    { label: 'club_fee', value: 'club_fee' },
    { label: 'donation', value: 'donation' },
  ];

  loading = signal(false);

  @ViewChild('filter') filter!: ElementRef;

  globalLoading = signal<boolean>(false);
  payments = signal<IPayment[]>(<IPayment[]>[]);
  uniquePaymentsByUser = computed<IPayment[]>(() => {
    const map = new Map<number, IPayment>();

    for (const payment of this.payments()) {
      if (!map.has(payment.user_id)) {
        map.set(payment.user_id, payment);
      }
    }

    return Array.from(map.values());
  });

  constructor(private httpClient: HttpClient) {}

  ngOnInit() {
    this.getPayments();
  }

  getPayments() {
    this.loading.set(true);
    this.httpClient
      .get<IGeneralResponse<{ user: IUser }>>(`http://${BASE_URL}/server/api/auth/me`)
      .pipe(
        map(({ data }) => {
          if (!data) return null;
          return data.user;
        }),
        catchError(() => of(null)),
        filter(Boolean),
        switchMap(({ role }) => {
            const url = role === 'admin' ? `http://${BASE_URL}/server/api/payments` : `http://${BASE_URL}/server/api/payments/me`;
            return this.httpClient
                .get<IGeneralResponse<IPayment[]>>(url)
                .pipe(
                    delay(500),
                    map(({ data }) => {
                        if (!data) return <IPayment[]>[];
                        return data;
                    }),
                    catchError(() => of(<IPayment[]>[])),
                );
        }),
        finalize(() => this.loading.set(false)),
      )
      .subscribe((resp) => {
        this.payments.set(resp);
        this.representatives = this.uniquePaymentsByUser().map(
          ({ user_first_name, user_last_name, user_id }) => ({
            user_first_name,
            user_last_name,
            user_id,
          }),
        );
      });
  }

  onGlobalFilter(table: Table, event: Event) {
    table.filterGlobal((event.target as HTMLInputElement).value, 'contains');
  }

  clear(table: Table) {
    table.clear();
    this.filter.nativeElement.value = '';
  }

  getSeverity(status: string) {
    switch (status) {
      case 'completed':
        return 'success';
      case 'pending':
        return 'warn';

      case 'failed':
        return 'danger';

      default:
        return 'info';
    }
  }

  getSeverityPaymentType(status: string) {
    switch (status) {
      case 'club_fee':
        return 'info';
      case 'event_fee':
        return 'secondary';

      case 'donation':
        return 'warn';
      default:
        return 'info';
    }
  }
}

export interface IPayment {
  payment_id: number;
  user_id: number;
  user_first_name: string;
  user_last_name: string;
  club_id: number;
  club_name: string;
  payment_type: 'club_fee' | 'event_fee' | 'donation' | string;
  event_id: number | null;
  event_title: string | null;
  amount: number;
  currency: string;
  status: 'completed' | 'pending' | 'failed' | 'refunded' | string;
  description: string;
  payment_date: string; // или можно использовать Date
}
