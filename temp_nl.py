with open('views/Estadisticas/estadisticas_manual.php', encoding='utf-8') as f:
    for i, line in enumerate(f, 1):
        if 20 <= i <= 80:
            print(f"{i:04d}: {line.rstrip()}" )
