from django.contrib import admin

from bitrix_auth.models import ApplicationInstallation, Bitrix24Account


@admin.register(Bitrix24Account)
class Bitrix24AccountAdmin(admin.ModelAdmin):
    def get_list_display(self, request):
        return tuple(field.name for field in self.model._meta.fields)

    readonly_fields = ("id",)


@admin.register(ApplicationInstallation)
class ApplicationInstallationAdmin(admin.ModelAdmin):
    def get_list_display(self, request):
        return tuple(field.name for field in self.model._meta.fields)

    readonly_fields = ("id",)
