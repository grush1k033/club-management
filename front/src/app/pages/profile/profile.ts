import { Component, signal } from '@angular/core';
import { FluidModule } from 'primeng/fluid';
import { InputTextModule } from 'primeng/inputtext';
import { ButtonModule } from 'primeng/button';
import { SelectModule } from 'primeng/select';
import {
  FormControl,
  FormGroup,
  FormsModule,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import { TextareaModule } from 'primeng/textarea';
import { PasswordDirective } from 'primeng/password';
import { BlockUI } from 'primeng/blockui';
import { ProgressSpinner } from 'primeng/progressspinner';
import { IGeneralResponse } from '@/pages/auth/login';
import { IUser } from '@/pages/events/events';
import { catchError, delay, filter, finalize, map, tap } from 'rxjs/operators';
import { of } from 'rxjs';
import { HttpClient } from '@angular/common/http';
import { Avatar } from 'primeng/avatar';
import { Skeleton } from 'primeng/skeleton';
import { MessageService } from 'primeng/api';
import { Toast } from 'primeng/toast';
import { BASE_URL } from '../../../constants';

@Component({
  selector: 'app-profile',
  standalone: true,
  imports: [
    InputTextModule,
    FluidModule,
    ButtonModule,
    SelectModule,
    FormsModule,
    TextareaModule,
    PasswordDirective,
    BlockUI,
    ProgressSpinner,
    ReactiveFormsModule,
    Avatar,
    Skeleton,
    Toast,
  ],
  providers: [MessageService],
  template: ` <p-toast></p-toast>
    <p-blockUI [blocked]="globalLoading()">
      <div style="display: grid; height: 100%; width: 100%; place-items: center">
        <p-progressSpinner></p-progressSpinner>
      </div>
    </p-blockUI>
    <p-fluid [formGroup]="form">
      <div class="flex">
        <div class="card flex flex-col gap-6 w-full">
          <div
            class="flex flex-wrap gap-2 w-full"
            style="align-items: center"
          >
            @if (form.value.firstName && form.value.lastName) {
              <p-avatar
                [label]="form.value.firstName[0] + form.value.lastName[0]"
                class="mr-2"
                size="xlarge"
                shape="circle"
              />
              <div class="font-semibold text-xl">
                {{ form.value.firstName }} {{ form.value.lastName }}
              </div>
              <div class="balance flex gap-6 ml-auto items-center pr-2">
                <i
                  class="pi pi-credit-card"
                  style="font-size: 20px; color: var(--primary-color)"
                ></i>
                <div style="color: var(--color-yellow-500)">
                  + {{ this.authUser()?.balance }}
                  {{ this.authUser()?.currency }}
                </div>
              </div>
            } @else {
              <p-skeleton
                width="55px"
                height="55px"
                borderRadius="50%"
              ></p-skeleton>
              <p-skeleton
                width="300px"
                height="20px"
                borderRadius="8px"
              ></p-skeleton>
            }
          </div>

          <div class="flex flex-col md:flex-row gap-6">
            <div class="flex flex-wrap gap-2 w-full">
              <label for="firstname2">Firstname</label>
              <input
                [formControl]="form.controls.firstName"
                pInputText
                id="firstname2"
                type="text"
              />
            </div>
            <div class="flex flex-wrap gap-2 w-full">
              <label for="lastname2">Lastname</label>
              <input
                [formControl]="form.controls.lastName"
                pInputText
                id="lastname2"
                type="text"
              />
            </div>
          </div>
          <div class="flex flex-col grow basis-0 gap-2">
            <label for="email2">Email</label>
            <input
              [formControl]="form.controls.email"
              pInputText
              id="email2"
              type="text"
            />
          </div>
          <div class="flex flex-col md:flex-row gap-6">
            <div class="flex flex-wrap gap-2 w-full">
              <label for="password1">Password</label>
              <input
                [formControl]="form.controls.password"
                pPassword
                id="password1"
                type="password"
              />
            </div>
            <div class="flex flex-wrap gap-2 w-full">
              <label for="password2">Repeat Password</label>
              <input
                [formControl]="form.controls.repeatPassword"
                pPassword
                id="password2"
                type="password"
              />
            </div>
          </div>
          <p-button
            (onClick)="updateUser()"
            [disabled]="form.invalid || form.value.password !== form.value.repeatPassword"
            label="Edit profile"
            [fluid]="false"
          ></p-button>
        </div>
      </div>
    </p-fluid>`,
})
export class Profile {
  globalLoading = signal<boolean>(false);
  authUser = signal<IUser | null>(null);
  form: FormType = new FormGroup({
    firstName: new FormControl<string | null>(null, Validators.required),
    email: new FormControl<string | null>(null, [Validators.required, Validators.email]),
    lastName: new FormControl<string | null>(null, Validators.required),
    password: new FormControl<string | null>(null, Validators.required),
    repeatPassword: new FormControl<string | null>(null, [
      Validators.required,
      Validators.minLength(6),
    ]),
  });

  constructor(
    private httpClient: HttpClient,
    private messageService: MessageService,
  ) {
    this.globalLoading.set(true);
    this.httpClient
      .get<IGeneralResponse<{ user: IUser }>>(`http://${BASE_URL}/server/api/auth/me`)
      .pipe(
        delay(500),
        map(({ data }) => {
          if (!data) return null;
          return data.user;
        }),
        catchError(() => of(null)),
        filter(Boolean),
        tap((user) => {
          this.authUser.set(user);
        }),
        finalize(() => this.globalLoading.set(false)),
      )
      .subscribe(() => {
        this.form.patchValue({
          firstName: this.authUser()?.first_name,
          lastName: this.authUser()?.last_name,
          email: this.authUser()?.email,
        });
      });
  }

  updateUser() {
    this.globalLoading.set(true);
    this.httpClient
      .patch(`http://${BASE_URL}/server/api/members/` + this.authUser()?.id, {
        first_name: this.form.value.firstName,
        last_name: this.form.value.lastName,
        email: this.form.value.email,
        password: this.form.value.password,
      })
      .pipe(
        delay(500),
        catchError((err) => {
            this.messageService.add({
                severity: 'error',
                summary: 'Error',
                detail: err.error.message,
                life: 3000,
            });
            return of(null)
        }),
        finalize(() => {
          this.globalLoading.set(false);
        }),
      )
      .subscribe((resp: any) => {
          if (resp) {
          this.messageService.add({
            severity: 'success',
            summary: 'Successful',
            detail: resp.message,
            life: 3000,
          });
        }
      });
  }
}

export type FormType = FormGroup<{
  firstName: FormControl<string | null>;
  email: FormControl<string | null>;
  lastName: FormControl<string | null>;
  password: FormControl<string | null>;
  repeatPassword: FormControl<string | null>;
}>;
