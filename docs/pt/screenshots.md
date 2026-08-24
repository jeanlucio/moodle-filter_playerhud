# 🖼️ Capturas de Tela

Navegue pelas setas, pelos pontos, ou pelas teclas de seta do teclado. Clique na imagem para ampliar.

<div class="screenshot-carousel-wrap">
  <div class="screenshot-carousel">
    <button type="button" class="carousel-arrow carousel-prev" aria-label="Tela anterior">&#8249;</button>

    <div class="carousel-track">
    {% for shot in site.data.screenshots %}
      <div class="carousel-slide{% if forloop.first %} is-active{% endif %}">
        <button type="button" class="screenshot-thumb" data-gallery="filter_playerhud"
            data-full="{{ '/assets/img/screenshots/' | append: shot.file | relative_url }}"
            data-caption="{{ shot.caption_pt }}">
          <img src="{{ '/assets/img/screenshots/' | append: shot.file | relative_url }}"
              alt="{{ shot.caption_pt }}" loading="lazy">
        </button>
      </div>
    {% endfor %}
    </div>

    <button type="button" class="carousel-arrow carousel-next" aria-label="Próxima tela">&#8250;</button>
  </div>

  <p class="carousel-caption">{{ site.data.screenshots.first.caption_pt }}</p>

  <div class="carousel-meta">
    <p class="carousel-counter"><span class="carousel-current">1</span> / {{ site.data.screenshots.size }}</p>
    <div class="carousel-dots">
    {% for shot in site.data.screenshots %}
      <button type="button" class="carousel-dot{% if forloop.first %} is-active{% endif %}"
          aria-label="Captura {{ forloop.index }}"></button>
    {% endfor %}
    </div>
  </div>
</div>
