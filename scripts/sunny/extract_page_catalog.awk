BEGIN { in_pages=0; page=""; name=""; profile="custom"; access=""; support="" }
/^[[:space:]]*'pages' => \[/ { in_pages=1; next }
in_pages && /^[[:space:]]{8}'[a-z0-9_]+' => \[/ {
  if (page != "") {
    printf "%s|%s|%s|%s|%s\n", page, name, profile, access, support;
  }
  match($0, /'([a-z0-9_]+)'/, m);
  page = m[1]; name=""; profile="custom"; access=""; support="";
  next;
}
in_pages && page != "" && /^[[:space:]]{12}'name' => '/ {
  line = $0; sub(/^[[:space:]]*'name' => '/, "", line); sub(/',?[[:space:]]*$/, "", line); name = line; next;
}
in_pages && page != "" && /^[[:space:]]{12}'role_capabilities' => \$profiles\['[a-z_0-9]+'\]/ {
  match($0, /\$profiles\['([a-z_0-9]+)'\]/, m); profile = m[1]; next;
}
in_pages && page != "" && /^[[:space:]]{12}'access_roles' => \[/ {
  line = $0; gsub(/.*\[/, "", line); gsub(/\].*/, "", line); gsub(/'/, "", line); gsub(/[[:space:]]/, "", line); access = line; next;
}
in_pages && page != "" && /^[[:space:]]{12}'supporting_pages' => \[/ {
  line = $0; gsub(/.*\[/, "", line); gsub(/\].*/, "", line); gsub(/'/, "", line); gsub(/[[:space:]]/, "", line); support = line; next;
}
END { if (page != "") printf "%s|%s|%s|%s|%s\n", page, name, profile, access, support; }
