import { Component, DestroyRef, inject, OnInit, signal, ViewChild } from '@angular/core';
import { ConfirmationService, MessageService } from 'primeng/api';
import { Table, TableModule } from 'primeng/table';
import { CommonModule } from '@angular/common';
import { FormControl, FormsModule } from '@angular/forms';
import { ButtonModule } from 'primeng/button';
import { RippleModule } from 'primeng/ripple';
import { ToastModule } from 'primeng/toast';
import { ToolbarModule } from 'primeng/toolbar';
import { RatingModule } from 'primeng/rating';
import { InputTextModule } from 'primeng/inputtext';
import { TextareaModule } from 'primeng/textarea';
import { SelectModule } from 'primeng/select';
import { RadioButtonModule } from 'primeng/radiobutton';
import { InputNumberModule } from 'primeng/inputnumber';
import { DialogModule } from 'primeng/dialog';
import { TagModule } from 'primeng/tag';
import { InputIconModule } from 'primeng/inputicon';
import { IconFieldModule } from 'primeng/iconfield';
import { ConfirmDialogModule } from 'primeng/confirmdialog';
import { HttpClient } from '@angular/common/http';
import { IGeneralResponse } from '@/pages/auth/login';
import {
  catchError,
  delay,
  distinctUntilChanged,
  filter,
  finalize,
  map,
  switchMap,
  tap,
} from 'rxjs/operators';
import { debounceTime, of } from 'rxjs';
import { Skeleton } from 'primeng/skeleton';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { HasPermissionDirective } from '@/pages/events/has-permission.directive';
import { IUser } from '@/pages/events/events';
import { BlockUI } from 'primeng/blockui';
import { ProgressSpinner } from 'primeng/progressspinner';
import { BASE_URL } from '../../../constants';

interface Column {
  field: string;
  header: string;
  customExportHeader?: string;
}

interface ExportColumn {
  title: string;
  dataKey: string;
}

@Component({
  selector: 'app-members',
  standalone: true,
  imports: [
    CommonModule,
    TableModule,
    FormsModule,
    ButtonModule,
    RippleModule,
    ToastModule,
    ToolbarModule,
    RatingModule,
    InputTextModule,
    TextareaModule,
    SelectModule,
    RadioButtonModule,
    InputNumberModule,
    DialogModule,
    TagModule,
    InputIconModule,
    IconFieldModule,
    ConfirmDialogModule,
    Skeleton,
    HasPermissionDirective,
    BlockUI,
    ProgressSpinner,
  ],
  template: `
    <p-toast></p-toast>
    <p-blockUI [blocked]="globalLoading()">
      <div style="display: grid; height: 100%; width: 100%; place-items: center">
        <p-progressSpinner></p-progressSpinner>
      </div>
    </p-blockUI>
    <p-toolbar styleClass="mb-6">
      <ng-template #start>
        <p-button
          *appHasPermission="{
            role: authUser?.role,
          }"
          severity="secondary"
          label="Delete"
          icon="pi pi-trash"
          outlined
          (onClick)="deleteSelectedProducts()"
          [disabled]="!selectedMembers || !selectedMembers.length"
        />
      </ng-template>

      <ng-template #end>
        <p-button
          label="Export"
          icon="pi pi-upload"
          severity="secondary"
          (onClick)="exportCSV()"
        />
      </ng-template>
    </p-toolbar>

    @if (loading()) {
      <p-skeleton
        width="100%"
        height="calc(100vh - 250px)"
        borderRadius="8px"
      ></p-skeleton>
    } @else {
      <p-table
        #dt
        [value]="members()"
        [rows]="10"
        [columns]="cols"
        [paginator]="true"
        [tableStyle]="{ 'min-width': '75rem' }"
        [(selection)]="selectedMembers"
        [rowHover]="true"
      >
        <ng-template #caption>
          <div class="flex items-center justify-between">
            <h5 class="m-0">Members management</h5>
            <p-iconfield>
              <p-inputicon styleClass="pi pi-search" />
              <input
                pInputText
                type="text"
                (input)="onGlobalFilter($event)"
                placeholder="Search..."
              />
              @if (searchLoading()) {
                <p-inputicon class="pi pi-spin pi-spinner" />
              }
            </p-iconfield>
          </div>
        </ng-template>
        <ng-template #header>
          <tr>
            <th
              *appHasPermission="{
                role: authUser?.role,
              }"
              style="width: 3rem"
            >
              <p-tableHeaderCheckbox />
            </th>
            <th style="min-width: 16rem">Member</th>
            <th style="min-width:16rem">Contact</th>
            <th>Club</th>
            <th style="min-width: 8rem">Role</th>
            <th style="min-width:10rem">Status</th>
            <th
              pSortableColumn="events_registered_count"
              style="max-width: 7rem"
            >
              Events
              <p-sortIcon field="events_registered_count" />
            </th>
            <th
              pSortableColumn="user_balance"
              style="min-width:10rem"
              *appHasPermission="{
                role: authUser?.role,
              }"
            >
              Balance
              <p-sortIcon field="user_balance" />
            </th>
            <th style="min-width: 12rem"></th>
          </tr>
        </ng-template>
        <ng-template
          #body
          let-member
        >
          <tr>
            <td
              *appHasPermission="{
                role: authUser?.role,
              }"
              style="width: 3rem"
            >
              <p-tableCheckbox [value]="member" />
            </td>
            <td style="min-width: 12rem">{{ member.user_full_name }}</td>
            <td style="min-width: 16rem">
              <div style="display: flex; flex-direction: column; gap: 2px">
                <div>{{ member.user_email }}</div>
                <div style="color: gray">{{ member.user_phone }}</div>
              </div>
            </td>
            <td>{{ member.club_name }}</td>
            <td>
              <p-tag
                [value]="member.user_role"
                [severity]="userRoleSaverity[member.user_role]"
              ></p-tag>
            </td>
            <td>
              <p-tag
                [value]="member.user_status"
                [severity]="
                  member.user_status === 'Active' || member.user_status === 'Активен'
                    ? 'success'
                    : 'danger'
                "
              ></p-tag>
            </td>
            <td>
              {{ member.events_registered_count }}
            </td>
            <td
              *appHasPermission="{
                role: authUser?.role,
              }"
            >
              {{ member.user_balance }} USD
            </td>
            <td>
              <p-button
                *appHasPermission="{
                  role: authUser?.role,
                }"
                icon="pi pi-trash"
                severity="danger"
                [rounded]="true"
                [outlined]="true"
                (click)="deleteProduct(member)"
              />
            </td>
          </tr>
        </ng-template>
      </p-table>
    }
    <p-confirmdialog [style]="{ width: '450px' }"></p-confirmdialog>
  `,
  providers: [MessageService, ConfirmationService],
})
export class Members implements OnInit {
  productDialog: boolean = false;

  selectedMembers!: UserReportRecord[] | null;

  submitted: boolean = false;

  statuses!: any[];

  @ViewChild('dt') dt!: Table;

  cols = [
    {
      field: 'user_full_name',
      header: 'Member',
      customExportHeader: 'Full Name',
    },
    {
      field: 'user_email',
      header: 'Contact',
    },
    {
      field: 'club_name',
      header: 'Club',
    },
    {
      field: 'user_role',
      header: 'Role',
    },
    {
      field: 'user_status',
      header: 'Status',
    },
    {
      field: 'events_registered_count',
      header: 'Members',
    },
    {
      field: 'events_registered_count',
      header: 'Members',
    },
  ];

  loading = signal(true);
  searchLoading = signal(false);
  globalLoading = signal<boolean>(false);

  members = signal<UserReportRecord[]>([]);
  userRoleSaverity: any = {
    member: 'info',
    admin: 'danger',
    club_owner: 'warn',
  };

  private httpClient = inject(HttpClient);
  private destroyRef = inject(DestroyRef);
  authUser: IUser | null = null;
  headerDialog = signal('');

  constructor(
    private messageService: MessageService,
    private confirmationService: ConfirmationService,
  ) {
    this.httpClient
      .get<IGeneralResponse<{ user: IUser }>>(`http://${BASE_URL}/server/api/auth/me`)
      .pipe(
        map(({ data }) => {
          if (!data) return null;
          return data.user;
        }),
        catchError(() => of(null)),
        filter(Boolean),
        tap((user) => {
          this.authUser = user;
        }),
      )
      .subscribe();
    this.searchValue.valueChanges
      .pipe(
        takeUntilDestroyed(this.destroyRef),
        distinctUntilChanged(),
        debounceTime(400),
        switchMap((value) => {
          this.searchLoading.set(true);
          return this.httpClient
            .get<
              IGeneralResponse<{
                users: UserReportRecord[];
              }>
            >(`http://${BASE_URL}/server/api/members/search?query=${value}`)
            .pipe(
              delay(500),
              map(({ data }) => {
                if (!data) return <UserReportRecord[]>[];
                return data.users;
              }),
              catchError(() => of(<UserReportRecord[]>[])),
              finalize(() => this.searchLoading.set(false)),
            );
        }),
      )
      .subscribe((resp) => this.members.set(resp));
  }

  exportCSV() {
    this.dt.exportCSV();
  }

  ngOnInit() {
    this.getMembers();
  }

  getMembers() {
    this.loading.set(true);
    this.httpClient
      .get<
        IGeneralResponse<{
          users: UserReportRecord[];
        }>
      >(`http://${BASE_URL}/server/api/reports/users-detailed`)
      .pipe(
        delay(500),
        map(({ data }) => {
          if (!data) return <UserReportRecord[]>[];
          return data.users;
        }),
        catchError(() => of(<UserReportRecord[]>[])),
        finalize(() => this.loading.set(false)),
      )
      .subscribe((resp) => this.members.set(resp));
  }

  searchValue = new FormControl<string>('', { nonNullable: true });

  onGlobalFilter(event: Event) {
    const inputEl = event.target as HTMLInputElement;
    this.searchValue.setValue(inputEl.value);
  }

  openNew() {
    this.headerDialog.set('Create member');
    this.submitted = false;
    this.productDialog = true;
  }

  editProduct(product: any) {
    this.headerDialog.set('Edit member');
    this.productDialog = true;
  }

  deleteSelectedProducts() {
    this.confirmationService.confirm({
      message: 'Are you sure you want to delete the selected members?',
      header: 'Confirm',
      icon: 'pi pi-exclamation-triangle',
      accept: () => {
        this.globalLoading.set(true);
        this.httpClient
          .post(`http://${BASE_URL}/server/api/members/multiple-delete`, {
            user_ids: this.selectedMembers?.map((item) => item.user_id) || [],
          })
          .pipe(
            delay(500),
            catchError(() => of(null)),
            finalize(() => this.globalLoading.set(false)),
          )
          .subscribe((resp) => {
            if (resp) {
              this.messageService.add({
                severity: 'success',
                summary: 'Successful',
                detail: 'Selected members deleted',
                life: 3000,
              });
              this.getMembers();
              this.selectedMembers = null;
            }
          });
      },
    });
  }

  hideDialog() {
    this.productDialog = false;
    this.submitted = false;
  }

  deleteProduct(member: UserReportRecord) {
    this.confirmationService.confirm({
      message: 'Are you sure you want to delete ' + member.user_full_name + '?',
      header: 'Confirm',
      icon: 'pi pi-exclamation-triangle',
      accept: () => {
        this.globalLoading.set(true);
        this.httpClient
          .delete(`http://${BASE_URL}/server/api/members/${member.user_id}`)
          .pipe(
            delay(500),
            catchError(() => of(null)),
            finalize(() => {
              this.globalLoading.set(false);
            }),
          )
          .subscribe((resp) => {
            if (resp) {
              member.user_status = 'Inactive';
              this.messageService.add({
                severity: 'success',
                summary: 'Successful',
                detail: `${member.user_full_name} deleted`,
                life: 3000,
              });
            } else {
              this.messageService.add({
                severity: 'error',
                summary: 'Error',
                detail: `${member.user_full_name} already deleted`,
                life: 3000,
              });
            }
          });
      },
    });
  }

  saveProduct() {
    this.productDialog = false;
    this.submitted = true;
  }
}

export interface UserReportRecord {
  user_id: string;
  user_full_name: string;
  user_email: string;
  user_phone: string;
  club_name: string;
  events_registered_count: number;
  user_role: string; // 'member' | 'club_owner' | 'admin'
  user_status: string; // 'Активен' | 'Неактивен' и т.д.
  user_balance: number;
  user_created_at: string; // Дата в формате 'YYYY-MM-DD HH:mm:ss'
}
