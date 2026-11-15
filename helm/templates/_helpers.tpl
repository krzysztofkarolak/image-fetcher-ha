{{/* Mapa nazw katalogów z helm/files/dashboards/* (nazwa: true) */}}
{{- define "image-fetcher.dashboardDirs" -}}
{{- $dirs := dict }}
{{- range $path, $_ := .Files.Glob "files/dashboards/*/*" }}
{{- $_ := set $dirs (base (dir $path)) true }}
{{- end }}
{{- toYaml $dirs }}
{{- end }}
